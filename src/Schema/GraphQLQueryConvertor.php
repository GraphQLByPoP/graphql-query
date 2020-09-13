<?php

declare(strict_types=1);

namespace GraphQLByPoP\GraphQLQuery\Schema;

use Exception;
use InvalidArgumentException;
use PoP\FieldQuery\QuerySyntax;
use PoP\FieldQuery\QueryHelpers;
use Youshido\GraphQL\Parser\Parser;
use Youshido\GraphQL\Parser\Ast\Field;
use Youshido\GraphQL\Parser\Ast\Query;
use Youshido\GraphQL\Execution\Request;
use PoP\Translation\TranslationAPIInterface;
use Youshido\GraphQL\Parser\Ast\FragmentReference;
use Youshido\GraphQL\Parser\Ast\TypedFragmentReference;
use Youshido\GraphQL\Parser\Ast\Interfaces\FieldInterface;
use PoP\Engine\DirectiveResolvers\IncludeDirectiveResolver;
use PoP\ComponentModel\Schema\FeedbackMessageStoreInterface;
use Youshido\GraphQL\Validator\RequestValidator\RequestValidator;
use Youshido\GraphQL\Exception\Interfaces\LocationableExceptionInterface;
use PoP\ComponentModel\Facades\Schema\FieldQueryInterpreterFacade;
use GraphQLByPoP\GraphQLQuery\ComponentConfiguration;
use GraphQLByPoP\GraphQLQuery\Schema\QuerySymbols;
use Youshido\GraphQL\Parser\Ast\ArgumentValue\VariableReference;
use Youshido\GraphQL\Parser\Ast\ArgumentValue\Variable;
use Youshido\GraphQL\Parser\Ast\ArgumentValue\InputList;
use Youshido\GraphQL\Parser\Ast\ArgumentValue\InputObject;
use Youshido\GraphQL\Parser\Ast\ArgumentValue\Literal;

class GraphQLQueryConvertor implements GraphQLQueryConvertorInterface
{
    protected $translationAPI;
    protected $feedbackMessageStore;

    public function __construct(
        TranslationAPIInterface $translationAPI,
        FeedbackMessageStoreInterface $feedbackMessageStore
    ) {
        $this->translationAPI = $translationAPI;
        $this->feedbackMessageStore = $feedbackMessageStore;
    }

    /**
     * Convert the GraphQL Query to PoP query in its requested form
     */
    public function convertFromGraphQLToFieldQuery(
        string $graphQLQuery,
        ?array $variables = [],
        bool $enableMultipleQueryExecution = false,
        ?string $operationName = null
    ): string {
        $operationFieldQueryPaths = $this->convertFromGraphQLToFieldQueryPaths(
            $graphQLQuery,
            $variables ?? [],
            $enableMultipleQueryExecution,
            $operationName
        );
        $fieldQueries = [];
        foreach ($operationFieldQueryPaths as $operationID => $fieldQueryPaths) {
            $operationFieldQueries = [];
            foreach ($fieldQueryPaths as $fieldQueryLevel) {
                // Join all connections with "."
                $operationFieldQueries[] = implode(
                    QuerySyntax::SYMBOL_RELATIONALFIELDS_NEXTLEVEL,
                    $fieldQueryLevel
                );
            }
            // Join all fields at the same level with ","
            $fieldQueries[] = implode(
                QuerySyntax::SYMBOL_QUERYFIELDS_SEPARATOR,
                $operationFieldQueries
            );
        }
        // Join all operators with a ";"
        return implode(
            QuerySyntax::SYMBOL_OPERATIONS_SEPARATOR,
            $fieldQueries
        );
    }

    /**
     * Convert the GraphQL Query to an array containing all the
     * parts from the query
     */
    protected function convertFromGraphQLToFieldQueryPaths(
        string $graphQLQuery,
        array $variables,
        bool $enableMultipleQueryExecution,
        ?string $operationName = null
    ): array {
        try {
            // If the validation throws an error, stop parsing the script
            $request = $this->parseAndCreateRequest(
                $graphQLQuery,
                $variables,
                $enableMultipleQueryExecution,
                $operationName
            );
            // Converting the query could also throw an Exception
            $fieldQueryPaths = $this->convertRequestToFieldQueryPaths($request);
        } catch (Exception $e) {
            // Save the error
            $errorMessage = $e->getMessage();
            // Retrieve the location of the error
            $location = ($e instanceof LocationableExceptionInterface) ?
                $e->getLocation()->toArray() :
                null;
            $extensions = [];
            if (!is_null($location)) {
                $extensions['location'] = $location;
            }
            $this->feedbackMessageStore->addQueryError($errorMessage, $extensions);
            // Returning nothing will not process the query
            return [];
        }
        return $fieldQueryPaths;
    }

    /**
     * Indicates if the variable must be dealt with as an expression: if its name starts with "_"
     *
     * @param string $variableName
     * @return boolean
     */
    public function treatVariableAsExpression(string $variableName): bool
    {
        return substr($variableName, 0, strlen(QuerySymbols::VARIABLE_AS_EXPRESSION_NAME_PREFIX)) == QuerySymbols::VARIABLE_AS_EXPRESSION_NAME_PREFIX;
    }

    protected function convertArgumentValue($value)
    {
        /**
         * If the value is of type InputList, then resolve the array with its variables (under `getValue`)
         */
        if ($value instanceof VariableReference &&
            ComponentConfiguration::enableVariablesAsExpressions() &&
            $this->treatVariableAsExpression($value->getName())
        ) {
            /**
             * If the value is a reference to a variable, and its name starts with "_",
             * then replace it with an expression, so its value can be computed on runtime
             */
            return QueryHelpers::getExpressionQuery($value->getName());
        } elseif ($value instanceof VariableReference || $value instanceof Variable) {
            return $value->getValue();
        } elseif ($value instanceof Literal) {
            /**
             * Support resolving other fields from the same type in field/directive arguments:
             * Replace posts(searchfor: "{{title}}") with posts(searchfor: "sprintf(%s, [title()])")
             */
            if (ComponentConfiguration::enableEmbeddableFields()) {
                // Inside the string, everything of pattern "{{field}}" is a field from the same type
                return preg_replace_callback(
                    '/(.*)?' . QuerySymbols::EMBEDDABLE_FIELD_PREFIX . '([a-zA-Z_][0-9a-zA-Z_]*)' . QuerySymbols::EMBEDDABLE_FIELD_SUFFIX . '(.*)?/',
                    function ($matches) {
                        $everythingBefore = $matches[1];
                        $everythingAfter = $matches[2];
                        $fieldName = $matches[3];
                        return 'sprintf("' . $everythingBefore . '%s' . $fieldName . '", [' . $everythingAfter . '()])';
                    },
                    $value->getValue()
                );
            }
            return $value->getValue();
        } elseif (is_array($value)) {
            /**
             * When coming from the InputList, its `getValue` is an array of Variables
             */
            return array_map(
                [$this, 'convertArgumentValue'],
                $value
            );
        } elseif ($value instanceof InputList || $value instanceof InputObject) {
            return array_map(
                [$this, 'convertArgumentValue'],
                $value->getValue()
            );
        }
        // Otherwise it may be a scalar value
        return $value;
    }

    protected function convertArguments(array $queryArguments): array
    {
        // Convert the arguments into an array
        $arguments = [];
        foreach ($queryArguments as $argument) {
            $value = $argument->getValue();
            $arguments[$argument->getName()] = $this->convertArgumentValue($value);
        }
        return $arguments;
    }

    protected function convertField(FieldInterface $field): string
    {
        $fieldQueryInterpreter = FieldQueryInterpreterFacade::getInstance();

        // Convert the arguments and directives into an array
        $arguments = $this->convertArguments($field->getArguments());
        $directives = [];
        foreach ($field->getDirectives() as $directive) {
            $directives[] = $fieldQueryInterpreter->getDirective(
                $directive->getName(),
                $this->convertArguments($directive->getArguments())
            );
        }
        return $fieldQueryInterpreter->getField(
            $field->getName(),
            $arguments,
            $field->getAlias(),
            false,
            $directives
        );
    }

    /**
     * Restrain fields to the model through directive <include(if:isType($model))>
     *
     * @return array
     */
    protected function restrainFieldsByTypeOrInterface(array $fragmentFieldPaths, string $fragmentModel): array
    {
        $fieldQueryInterpreter = FieldQueryInterpreterFacade::getInstance();
        // Create the <include> directive, if the fragment references the type or interface
        $includeDirective = $fieldQueryInterpreter->composeFieldDirective(
            IncludeDirectiveResolver::getDirectiveName(),
            $fieldQueryInterpreter->getFieldArgsAsString([
                'if' => $fieldQueryInterpreter->getField(
                    'or',
                    [
                        'values' => [
                            $fieldQueryInterpreter->getField(
                                'isType',
                                [
                                    'type' => $fragmentModel
                                ]
                            ),
                            $fieldQueryInterpreter->getField(
                                'implements',
                                [
                                    'interface' => $fragmentModel
                                ]
                            )
                        ],
                    ]
                ),
            ])
        );
        $fragmentFieldPaths = array_map(
            function (array $fragmentFieldPath) use ($includeDirective, $fieldQueryInterpreter): array {
                // The field can itself compose other fields. Get the 1st element
                // to apply the directive to the root property only
                $fragmentRootField = $fragmentFieldPath[0];

                // Add the directive to the current directives from the field
                $rootFieldDirectives = $fieldQueryInterpreter->getFieldDirectives((string)$fragmentRootField);
                if ($rootFieldDirectives) {
                    // The include directive comes first,
                    // so if it evals to false the upcoming directives are not executed
                    $rootFieldDirectives =
                        $includeDirective .
                        QuerySyntax::SYMBOL_FIELDDIRECTIVE_SEPARATOR .
                        $rootFieldDirectives;
                    // Also remove the directive from the root field, since it will be added again below
                    list(
                        $fieldDirectivesOpeningSymbolPos,
                    ) = QueryHelpers::listFieldDirectivesSymbolPositions($fragmentRootField);
                    $fragmentRootField = substr($fragmentRootField, 0, $fieldDirectivesOpeningSymbolPos);
                } else {
                    $rootFieldDirectives = $includeDirective;
                }

                // Replace the first element, adding the directive
                $fragmentFieldPath[0] =
                    $fragmentRootField .
                    QuerySyntax::SYMBOL_FIELDDIRECTIVE_OPENING .
                    $rootFieldDirectives .
                    QuerySyntax::SYMBOL_FIELDDIRECTIVE_CLOSING;
                return $fragmentFieldPath;
            },
            $fragmentFieldPaths
        );
        return $fragmentFieldPaths;
    }

    protected function processAndAddFieldPaths(Request $request, array &$queryFieldPaths, array $fields, array $queryField = []): void
    {
        // Iterate through the query's fields: properties, connections, fragments
        $queryFieldPath = $queryField;
        foreach ($fields as $field) {
            if ($field instanceof Field) {
                // Fields are leaves in the graph
                $queryFieldPaths[] = array_merge(
                    $queryFieldPath,
                    [$this->convertField($field)]
                );
            } elseif ($field instanceof Query) {
                // Queries are connections
                $nestedFieldPaths = $this->getFieldPathsFromQuery($request, $field);
                foreach ($nestedFieldPaths as $nestedFieldPath) {
                    $queryFieldPaths[] = array_merge(
                        $queryFieldPath,
                        $nestedFieldPath
                    );
                }
            } elseif ($field instanceof FragmentReference || $field instanceof TypedFragmentReference) {
                // Replace the fragment reference with its resolved information
                $fragmentReference = $field;
                if ($fragmentReference instanceof FragmentReference) {
                    $fragmentName = $fragmentReference->getName();
                    $fragment = $request->getFragment($fragmentName);
                    $fragmentFields = $fragment->getFields();
                    $fragmentType = $fragment->getModel();
                } elseif ($fragmentReference instanceof TypedFragmentReference) {
                    $fragmentFields = $fragmentReference->getFields();
                    $fragmentType = $fragmentReference->getTypeName();
                }

                // Get the fields defined in the fragment
                $fragmentConvertedFieldPaths = [];
                $this->processAndAddFieldPaths($request, $fragmentConvertedFieldPaths, $fragmentFields);

                // Restrain those fields to the indicated type
                $fragmentConvertedFieldPaths = $this->restrainFieldsByTypeOrInterface($fragmentConvertedFieldPaths, $fragmentType);

                // Add them to the list of fields in the query
                foreach ($fragmentConvertedFieldPaths as $fragmentFieldPath) {
                    $queryFieldPaths[] = array_merge(
                        $queryFieldPath,
                        $fragmentFieldPath
                    );
                }
            }
        }
    }

    protected function getFieldPathsFromQuery(Request $request, Query $query): array
    {
        $queryFieldPaths = [];
        $queryFieldPath = [$this->convertField($query)];

        // Iterate through the query's fields: properties and connections
        if ($fields = $query->getFields()) {
            $this->processAndAddFieldPaths($request, $queryFieldPaths, $fields, $queryFieldPath);
        } else {
            // Otherwise, just add the query field, which doesn't have subfields
            $queryFieldPaths[] = $queryFieldPath;
        }

        return $queryFieldPaths;
    }

    /**
     * Convert the GraphQL to its equivalent fieldQuery.
     * The GraphQL syntax is explained in graphql.org
     *
     * @see https://graphql.org/learn/queries/
     */
    protected function convertRequestToFieldQueryPaths(Request $request): array
    {
        $fieldQueryPaths = [];
        foreach ($request->getQueries() as $query) {
            $operationLocation = $query->getLocation();
            $operationID = sprintf(
                '%s-%s',
                $operationLocation->getLine(),
                $operationLocation->getColumn()
            );
            $fieldQueryPaths[$operationID] = array_merge(
                $fieldQueryPaths[$operationID] ?? [],
                $this->getFieldPathsFromQuery($request, $query)
            );
        }
        return $fieldQueryPaths;
    }

    /**
     * Function copied from youshido/graphql/src/Execution/Processor.php
     *
     * @param [type] $payload
     * @param array $variables
     * @return void
     */
    protected function parseAndCreateRequest(
        string $payload,
        array $variables,
        bool $enableMultipleQueryExecution,
        ?string $operationName = null
    ): Request {
        if (empty($payload)) {
            throw new InvalidArgumentException(
                $this->translationAPI->__('Must provide an operation.', 'graphql-query')
            );
        }

        $parser  = new Parser();
        $parsedData = $parser->parse($payload);

        // GraphiQL sends the operationName to execute in the payload, under "operationName"
        // This is required when the payload contains multiple queries
        if (is_null($operationName)) {
            /**
             * If not enabling multiple query execution, validate that
             * only one operation was submitted.
             *
             * From the GraphQL spec:
             *
             * > If operationName is null:
             * >     If document contains exactly one operation.
             * >         Return the Operation contained in the document.
             * >     Otherwise produce a query error requiring operationName.
             *
             * @see https://spec.graphql.org/draft/#GetOperation()
             * @see https://spec.graphql.org/draft/#sel-EANLHDBFBGCBFDCBnmD
             */
            if (!$enableMultipleQueryExecution) {
                $operationCount = count($parsedData['queryOperations']) + count($parsedData['mutationOperations']);
                if ($operationCount > 1) {
                    throw new InvalidArgumentException(sprintf(
                        $this->translationAPI->__(
                            'Feature \'Multiple Query Execution\' is not enabled, so can execute 1 operation only, but %s operations were submitted (\'%s\')',
                            'graphql-query'
                        ),
                        $operationCount,
                        implode(
                            '\', \'',
                            array_merge(
                                array_map(
                                    function (array $operation): string {
                                        return $operation['name'];
                                    },
                                    $parsedData['queryOperations']
                                ),
                                array_map(
                                    function (array $operation): string {
                                        return $operation['name'];
                                    },
                                    $parsedData['mutationOperations']
                                )
                            )
                        )
                    ));
                }
            }
        } else {
            /**
             * Hack! Because GraphiQL does not allow to execute more than 1 operation,
             * (so it doesn't support query batching), then this artificial query
             * indicates to execute all (i.e. execute all operations but this one):
             * ```
             *   query __ALL { id }
             * ```
             */
            if ($enableMultipleQueryExecution && strtoupper($operationName) == ClientSymbols::GRAPHIQL_QUERY_BATCHING_OPERATION_NAME) {
                // Find the position and number of queries processed by this operation
                foreach ($parsedData['queryOperations'] as $queryOperation) {
                    if ($queryOperation['name'] == $operationName) {
                        array_splice(
                            $parsedData['queries'],
                            $queryOperation['position'],
                            $queryOperation['numberItems']
                        );
                        break;
                    }
                }
                foreach ($parsedData['mutationOperations'] as $mutationOperation) {
                    if ($mutationOperation['name'] == $operationName) {
                        array_splice(
                            $parsedData['mutations'],
                            $mutationOperation['position'],
                            $mutationOperation['numberItems']
                        );
                        break;
                    }
                }
            } else {
                /**
                 * Find the position and number of queries processed by this operation
                 * If the operation is the same one, then that's it, retrieve it.
                 *
                 * Otherwise, remove it from the entry, so that if sending an operationName,
                 * that does not exist, the set to execute is an empty array
                 */
                for ($i = count($parsedData['queryOperations']) - 1; $i >= 0; $i--) {
                    $queryOperation = $parsedData['queryOperations'][$i];
                    if ($queryOperation['name'] == $operationName) {
                        $parsedData['queries'] = array_slice(
                            $parsedData['queries'],
                            $queryOperation['position'],
                            $queryOperation['numberItems']
                        );
                        break;
                    } else {
                        array_splice(
                            $parsedData['queries'],
                            $queryOperation['position'],
                            $queryOperation['numberItems']
                        );
                    }
                }
                for ($i = count($parsedData['mutationOperations']) - 1; $i >= 0; $i--) {
                    $mutationOperation = $parsedData['mutationOperations'][$i];
                    if ($mutationOperation['name'] == $operationName) {
                        $parsedData['mutations'] = array_slice(
                            $parsedData['mutations'],
                            $mutationOperation['position'],
                            $mutationOperation['numberItems']
                        );
                        break;
                    } else {
                        array_splice(
                            $parsedData['mutations'],
                            $mutationOperation['position'],
                            $mutationOperation['numberItems']
                        );
                    }
                }
                /**
                 * From the GraphQL spec:
                 *
                 * > If operation was not found, produce a query error.
                 *
                 * @see https://spec.graphql.org/draft/#GetOperation()
                 * @see https://spec.graphql.org/draft/#sel-IANLHCDBDCAACCmB3L
                 */
                if (empty($parsedData['queries']) && empty($parsedData['mutations'])) {
                    throw new InvalidArgumentException(sprintf(
                        $this->translationAPI->__('No operation with name \'%s\' was submitted.', 'graphql-query'),
                        $operationName
                    ));
                }
            }
        }

        $request = new Request($parsedData, $variables);

        // If the validation fails, it will throw an exception
        (new RequestValidator())->validate($request);

        // Return the request
        return $request;
    }
}
