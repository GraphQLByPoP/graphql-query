<?php
namespace PoP\GraphQLAPIQuery\Schema;

use Exception;
use InvalidArgumentException;
use Youshido\GraphQL\Parser\Parser;
use Youshido\GraphQL\Execution\Request;
use PoP\Translation\TranslationAPIInterface;
use PoP\ComponentModel\Schema\FeedbackMessageStoreInterface;
use Youshido\GraphQL\Validator\RequestValidator\RequestValidator;

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
            $parsedData = $this->parseAndCreateRequest($graphQLQuery, $variables);
        } catch (Exception $e) {
            // Save the error
            $errorMessage = $e->getMessage();
            $this->feedbackMessageStore->addQueryError($errorMessage);
            // Returning nothing will not process the query
            return '';
        }
        // TODO
        $fieldQuery = sprintf(
            'echo("%s")@parsedData',
            print_r($parsedData, true)
        );
        // $fieldQuery = '';
        return $fieldQuery;
    }

    /**
     * Function copied from youshido/graphql/src/Execution/Processor.php
     *
     * @param [type] $payload
     * @param array $variables
     * @return void
     */
    protected function parseAndCreateRequest($payload, $variables = [])
    {
        if (empty($payload)) {
            throw new InvalidArgumentException($this->translationAPI->__('Must provide an operation.'));
        }

        $parser  = new Parser();
        $parsedData = $parser->parse($payload);
        $request = new Request($parsedData, $variables);

        // If the validation fails, it will throw an exception
        (new RequestValidator())->validate($request);

        // Return the parsed data
        return $parsedData;
    }
}
