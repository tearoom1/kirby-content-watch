<?php

namespace TearoomOne\ContentWatch;

use RuntimeException;

/**
 * A restore that is not possible, with a message for the Panel.
 * The code is the HTTP status for the API response.
 */
class RestoreException extends RuntimeException
{
}
