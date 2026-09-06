<?php

declare(strict_types=1);

namespace Adn\WebTerm\Gateway;

use RuntimeException;

/**
 * Base for gateway control-plane failures. Messages reach an operator, so they
 * name the likely cause and the command that fixes it.
 */
class GatewayException extends RuntimeException {}
