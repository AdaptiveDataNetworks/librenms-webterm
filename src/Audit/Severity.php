<?php

declare(strict_types=1);

namespace Adn\WebTerm\Audit;

/**
 * Our own severity vocabulary, with values chosen to match LibreNMS's
 * LibreNMS\Enum\Severity so the mapping is an identity on the integer.
 *
 * Duplicating the enum rather than importing it keeps the rule that no
 * LibreNMS symbol is referenced outside src/Librenms/ -- and means our audit
 * code still works, and still tests, without LibreNMS present.
 */
enum Severity: int
{
    case Unknown = 0;
    case Ok = 1;
    case Info = 2;
    case Notice = 3;
    case Warning = 4;
    case Error = 5;
}
