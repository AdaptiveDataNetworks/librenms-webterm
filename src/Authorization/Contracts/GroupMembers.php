<?php

declare(strict_types=1);

namespace AdaptiveDataNetworks\WebTerm\Authorization\Contracts;

/**
 * The reverse of GroupSource: which devices are in a group.
 *
 * Separate because it is used for a different purpose with a different safety
 * requirement. GroupSource answers "which groups is this device in", for
 * matching grants and credentials. This answers "which devices should I write
 * target rows for", and must refuse anything whose membership can change
 * without a human -- hence the null return rather than an empty list.
 */
interface GroupMembers
{
    /**
     * Device ids in a STATIC group.
     *
     * @return list<int>|null null when the group is missing or dynamic
     */
    public function staticMembersOf(int $groupId): ?array;
}
