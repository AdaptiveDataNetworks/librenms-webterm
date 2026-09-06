<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Gateway;

/**
 * Plugin and gateway disagree on the protocol. Version skew is the steady
 * state -- LibreNMS re-resolves the plugin on every update while the binary is
 * untouched -- so this must be a clear, actionable message rather than a crash.
 */
final class GatewayVersionException extends GatewayException {}
