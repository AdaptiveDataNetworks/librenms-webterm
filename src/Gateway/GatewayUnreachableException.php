<?php

declare(strict_types=1);

namespace Adn\WebTerm\Gateway;

/**
 * The gateway did not answer. Distinct from a protocol error because the
 * remedy is different: check the service, not the versions.
 */
final class GatewayUnreachableException extends GatewayException {}
