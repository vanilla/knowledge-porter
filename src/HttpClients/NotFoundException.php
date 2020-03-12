<?php


namespace Vanilla\KnowledgePorter\HttpClients;

use Garden\Http\HttpResponse;
use Garden\Http\HttpResponseException;

/**
 * An exception that represents a specific 404 response.
 */
class NotFoundException extends HttpResponseException {
    /**
     * NotFoundException constructor.
     * @param HttpResponse $response
     * @param string $message
     */
    public function __construct(HttpResponse $response, string $message = "") {
        parent::__construct($response, $message);
    }
}
