<?php
namespace PoP\GraphQLAPIQuery\Schema;

use Exception;
use InvalidArgumentException;
use PoP\FieldQuery\QuerySyntax;
use Youshido\GraphQL\Parser\Parser;
use Youshido\GraphQL\Parser\Ast\Field;
use Youshido\GraphQL\Parser\Ast\Query;
use Youshido\GraphQL\Execution\Request;
use PoP\Translation\TranslationAPIInterface;
use Youshido\GraphQL\Parser\Ast\Interfaces\FieldInterface;
use PoP\ComponentModel\Schema\FeedbackMessageStoreInterface;
use Youshido\GraphQL\Validator\RequestValidator\RequestValidator;
use PoP\ComponentModel\Facades\Schema\FieldQueryInterpreterFacade;

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

    public function convertFromGraphQLToFieldQuery(string $graphQLQuery, ?array $variables): string
    {
        // If the validation throws an error, display it
        try {
            $request = $this->parseAndCreateRequest($graphQLQuery, $variables);
        } catch (Exception $e) {
            // Save the error
            $errorMessage = $e->getMessage();
            $this->feedbackMessageStore->addQueryError($errorMessage);
            // Returning nothing will not process the query
            return '';
        }
        return $this->convertRequestToFieldQuery($request);
    }

    protected function convertField(FieldInterface $field): string
    {
        $fieldQueryInterpreter = FieldQueryInterpreterFacade::getInstance();

        // Convert the directives into an array
        $directives = [];
        foreach ($field->getDirectives() as $directive) {
            $directives[] = $fieldQueryInterpreter->composeFieldDirective(
                $directive->getName(),
                $fieldQueryInterpreter->getFieldArgsAsString($directive->getArguments())
            );
        }
        return $fieldQueryInterpreter->getField(
            $field->getName(),
            $field->getArguments(),
            $field->getAlias(),
            false,
            $directives
        );
    }

    protected function getFieldsFromQuery(Query $query): array
    {
        $queryFields = [];
        $queryField = $this->convertField($query);

        // Iterate through the query's fields: properties and connections
        if ($fields = $query->getFields()) {
            foreach ($fields as $field) {
                // Fields are leaves in the graph
                if ($field instanceof Field) {
                    $queryFields[] =
                        $queryField.
                        QuerySyntax::SYMBOL_RELATIONALFIELDS_NEXTLEVEL.
                        $this->convertField($field);
                } elseif ($field instanceof Query) {
                    // Queries are connections
                    $nestedFields = $this->getFieldsFromQuery($field);
                    foreach ($nestedFields as $nestedField) {
                        $queryFields[] =
                            $queryField.
                            QuerySyntax::SYMBOL_RELATIONALFIELDS_NEXTLEVEL.
                            $nestedField;
                    }
                }
            }
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

        // field
        foreach ($request->getQueries() as $query) {
            $fieldQueries = array_merge(
                $fieldQueries,
                $this->getFieldsFromQuery($query)
            );
        }

        // nested field

        // field arguments

        // aliases

        // fragments

        // operation name

        // variables

        //variables inside fragments

        // default variables

        // directives

        // inline fragments

        // mutations
        // TODO

        $fieldQuery = implode(
            QuerySyntax::SYMBOL_QUERYFIELDS_SEPARATOR,
            $fieldQueries
        );
        return $fieldQuery;

        // // Testing
        // // $debug = $request;
        // $debug = [
        //     'getAllOperations()' => $request->getAllOperations(),
        //     'getQueries()' => $request->getQueries(),
        //     'getFragments()' => $request->getFragments(),
        //     // 'getFragment($name)' => $request->getFragment($name),
        //     'getMutations()' => $request->getMutations(),
        //     'hasQueries()' => $request->hasQueries(),
        //     'hasMutations()' => $request->hasMutations(),
        //     'hasFragments()' => $request->hasFragments(),
        //     'getVariables()' => $request->getVariables(),
        //     // 'getVariable($name)' => $request->getVariable($name),
        //     // 'hasVariable($name)' => $request->hasVariable($name),
        //     'getQueryVariables()' => $request->getQueryVariables(),
        //     'getFragmentReferences()' => $request->getFragmentReferences(),
        //     'getVariableReferences()' => $request->getVariableReferences(),
        // ];

        // // Temporary code for testing
        // $fieldQuery = sprintf(
        //     'echo("%s")@request',
        //     print_r($debug, true)
        // );
        // return $fieldQuery;
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
