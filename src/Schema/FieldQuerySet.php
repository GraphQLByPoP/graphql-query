<?php

declare(strict_types=1);

namespace PoP\GraphQLAPIQuery\Schema;

/**
 * Object containing a pair of [requestedFieldQuery, executableFieldQuery]
 *
 * @author Leonardo Losoviz <leo@getpop.org>
 */
class FieldQuerySet
{
    protected $requestedFieldQuery;
    protected $executableFieldQuery;

    public function __construct(
        string $requestedFieldQuery,
        string $executableFieldQuery
    ) {
        $this->requestedFieldQuery = $requestedFieldQuery;
        $this->executableFieldQuery = $executableFieldQuery;
    }

    public function getRequestedFieldQuery(): string
    {
        return $this->requestedFieldQuery;
    }

    public function getExecutableFieldQuery(): string
    {
        return $this->executableFieldQuery;
    }

    public function areRequestedAndExecutableFieldQueriesDifferent(): bool
    {
        return $this->requestedFieldQuery != $this->executableFieldQuery;
    }
}
