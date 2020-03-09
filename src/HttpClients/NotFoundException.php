<?php


namespace Vanilla\KnowledgePorter\HttpClients;

use Garden\Http\HttpResponse;
use Garden\Http\HttpResponseException;

/**
 * An exception that represents a specific 404 response.
 */
class NotFoundException extends HttpResponseException {
    public function __construct(HttpResponse $response, $message = "") {
        parent::__construct($response, $message);
    }
}
