<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Authorization\Contracts;

interface GroupSource
{
    /**
     * @param  object  $device  A LibreNMS App\Models\Device.
     * @return list<int>
     */
    public function staticGroupIdsFor(object $device): array;
}
