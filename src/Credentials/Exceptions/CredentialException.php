<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Credentials\Exceptions;

use RuntimeException;

/**
 * Base for credential resolution failures.
 *
 * Messages here reach an operator, so they must never carry secret material or
 * a raw upstream exception -- a Guzzle exception from Vault, for instance,
 * renders the request headers including X-Vault-Token.
 */
class CredentialException extends RuntimeException {}
