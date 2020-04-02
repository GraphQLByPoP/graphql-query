<?php
namespace PoP\GraphQLAPIQuery\Schema;

use Exception;
use InvalidArgumentException;
use PoP\FieldQuery\QueryUtils;
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
use PoP\GraphQLAPIQuery\ComponentConfiguration;
use PoP\GraphQLAPIQuery\Schema\QuerySymbols;
use Youshido\GraphQL\Parser\Ast\ArgumentValue\VariableReference;

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

    public function convertFromGraphQLToFieldQuery(string $graphQLQuery, ?array $variables = []): string
    {
        try {
            // If the validation throws an error, stop parsing the script
            $request = $this->parseAndCreateRequest($graphQLQuery, $variables);
            // Converting the query could also throw an Exception
            $fieldQuery = $this->convertRequestToFieldQuery($request);
        } catch (Exception $e) {
            // Save the error
            $errorMessage = $e->getMessage();
            // Retrieve the location of the error
            $location = ($e instanceof LocationableExceptionInterface) ?
                $e->getLocation()->toArray() :
                null;
            $this->feedbackMessageStore->addQueryError($errorMessage, $location);
            // Returning nothing will not process the query
            return '';
        }
        return $fieldQuery;
    }

    protected function convertArguments(array $queryArguments): array
    {
        // Convert the arguments into an array
        $arguments = [];
        foreach ($queryArguments as $argument) {
            $value = $argument->getValue();
            /**
             * If the value is a reference to a variable, and its name starts with "_",
             * then replace it with an expression, so its value can be computed on runtime
             */
            if (
                ComponentConfiguration::enableVariablesAsExpressions() &&
                $value instanceof VariableReference &&
                substr($value->getName(), 0, strlen(QuerySymbols::VARIABLE_AS_EXPRESSION_NAME_PREFIX)) == QuerySymbols::VARIABLE_AS_EXPRESSION_NAME_PREFIX
            ) {
                $arguments[$argument->getName()] = QueryHelpers::getExpressionQuery($value->getName());
            } else {
                $arguments[$argument->getName()] = $value->getValue();
            }
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
    protected function restrainFieldsByType(array $fragmentFields, string $fragmentModel, string $queryField): array
    {
        $fieldQueryInterpreter = FieldQueryInterpreterFacade::getInstance();
        // Create the <include> directive
        $includeDirective = $fieldQueryInterpreter->composeFieldDirective(
            IncludeDirectiveResolver::getDirectiveName(),
            $fieldQueryInterpreter->getFieldArgsAsString([
                'if' => $fieldQueryInterpreter->getField(
                    'isType',
                    [
                        'type' => $fragmentModel
                    ]
                ),
            ])
        );
        $fragmentFields = array_map(
            function($fragmentField) use($includeDirective, $fieldQueryInterpreter) {
                // The field can itself compose other fields. In that case, apply the directive to the root property only
                $dotPos = QueryUtils::findFirstSymbolPosition($fragmentField, QuerySyntax::SYMBOL_RELATIONALFIELDS_NEXTLEVEL, [QuerySyntax::SYMBOL_FIELDARGS_OPENING, QuerySyntax::SYMBOL_FIELDDIRECTIVE_OPENING], [QuerySyntax::SYMBOL_FIELDARGS_CLOSING, QuerySyntax::SYMBOL_FIELDDIRECTIVE_CLOSING], QuerySyntax::SYMBOL_FIELDARGS_ARGVALUESTRING_OPENING, QuerySyntax::SYMBOL_FIELDARGS_ARGVALUESTRING_CLOSING);
                if ($dotPos !== false) {
                    $fragmentRootField = substr($fragmentField, 0, $dotPos);
                } else {
                    $fragmentRootField = $fragmentField;
                }

                // Add the directive to the current directives from the field
                $rootFieldDirectives = $fieldQueryInterpreter->getFieldDirectives((string)$fragmentRootField);
                if ($rootFieldDirectives) {
                    // The include directive comes first, so if it evals to false the upcoming directives are not executed
                    $rootFieldDirectives =
                        $includeDirective.
                        QuerySyntax::SYMBOL_FIELDDIRECTIVE_SEPARATOR.
                        $rootFieldDirectives;
                    // Also remove the directive from the root field, since it will be added again below
                    list(
                        $fieldDirectivesOpeningSymbolPos,
                    ) = QueryHelpers::listFieldDirectivesSymbolPositions($fragmentRootField);
                    $fragmentRootField = substr($fragmentRootField, 0, $fieldDirectivesOpeningSymbolPos);
                } else {
                    $rootFieldDirectives = $includeDirective;
                }

                return
                    $fragmentRootField.
                    QuerySyntax::SYMBOL_FIELDDIRECTIVE_OPENING.
                    $rootFieldDirectives.
                    QuerySyntax::SYMBOL_FIELDDIRECTIVE_CLOSING.
                    (($dotPos !== false) ? substr($fragmentField, $dotPos) : '');
            },
            $fragmentFields
        );
        return $fragmentFields;
    }

    protected function processAndAddFields(Request $request, array &$queryFields, array $fields, string $queryField = ''): void
    {
        // Iterate through the query's fields: properties, connections, fragments
        $queryFieldPath =
            $queryField ?
                $queryField.QuerySyntax::SYMBOL_RELATIONALFIELDS_NEXTLEVEL :
                '';
        foreach ($fields as $field) {
            if ($field instanceof Field) {
                // Fields are leaves in the graph
                $queryFields[] =
                    $queryFieldPath.
                    $this->convertField($field);
            } elseif ($field instanceof Query) {
                // Queries are connections
                $nestedFields = $this->getFieldsFromQuery($request, $field);
                foreach ($nestedFields as $nestedField) {
                    $queryFields[] =
                        $queryFieldPath.
                        $nestedField;
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
                $fragmentConvertedFields = [];
                $this->processAndAddFields($request, $fragmentConvertedFields, $fragmentFields);

                // Restrain those fields to the indicated type
                $fragmentConvertedFields = $this->restrainFieldsByType($fragmentConvertedFields, $fragmentType, $queryField);

                // Add them to the list of fields in the query
                foreach ($fragmentConvertedFields as $fragmentField) {
                    $queryFields[] =
                        $queryFieldPath.
                        $fragmentField;
                }
            }
        }
    }

    protected function getFieldsFromQuery(Request $request, Query $query): array
    {
        $queryFields = [];
        $queryField = $this->convertField($query);

        // Iterate through the query's fields: properties and connections
        if ($fields = $query->getFields()) {
            $this->processAndAddFields($request, $queryFields, $fields, $queryField);
        } else {
            // Otherwise, just add the query field, which doesn't have subfields
            $queryFields[] = $queryField;
        }

        return $queryFields;
    }

    /**
     * Convert the GraphQL to its equivalent fieldQuery. The GraphQL syntax is explained in https://graphql.org/learn/queries/
     *
     * @param Request $request
     * @return string
     */
    protected function convertRequestToFieldQuery(Request $request): string
    {
        $fieldQueries = [];
        foreach ($request->getQueries() as $query) {
            $fieldQueries = array_merge(
                $fieldQueries,
                $this->getFieldsFromQuery($request, $query)
            );
        }

        $fieldQuery = implode(
            QuerySyntax::SYMBOL_QUERYFIELDS_SEPARATOR,
            $fieldQueries
        );
        return $fieldQuery;
    }

    /**
     * Function copied from youshido/graphql/src/Execution/Processor.php
     *
     * @param [type] $payload
     * @param array $variables
     * @return void
     */
    protected function parseAndCreateRequest($payload, $variables = []): Request
    {
        if (empty($payload)) {
            throw new InvalidArgumentException($this->translationAPI->__('Must provide an operation.'));
        }

        $parser  = new Parser();
        $parsedData = $parser->parse($payload);
        $request = new Request($parsedData, $variables);

        // If the validation fails, it will throw an exception
        (new RequestValidator())->validate($request);

        // Return the request
        return $request;
    }
}
