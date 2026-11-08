<?php

declare(strict_types=1);

namespace PelicanProvisioner\Domain;

/**
 * A quota chooses how its egg and node lists relate to the global rules. No mode keeps those rules. Additive adds the selection, including eggs and nodes the global rules leave out. Negative removes the selection. Overwrite ignores the global rules and uses the selection alone, and an empty overwrite allows none.
 */
final class QuotaAllowlist
{
    /**
     * @param  list<int>  $globalEggIds
     */
    public function eggs(bool $allowAll, array $globalEggIds, mixed $mode, mixed $userEggs, bool $overwriteAll = false): EggChoice
    {
        $selected = self::ids($userEggs);
        $global = self::ids($globalEggIds);

        return match (AccessMode::parse($mode)) {
            null => new EggChoice($allowAll, $allowAll ? [] : $global),
            AccessMode::Additive => $allowAll
                ? new EggChoice(true, [])
                : new EggChoice(false, self::union($global, $selected)),
            AccessMode::Negative => $allowAll
                ? new EggChoice(true, [], $selected)
                : new EggChoice(false, array_values(array_diff($global, $selected))),
            AccessMode::Overwrite => $overwriteAll
                ? new EggChoice(true, [])
                : new EggChoice(false, $selected),
        };
    }

    /**
     * @param  list<int>  $taggedNodeIds  Nodes the deployment tags already returned.
     * @return list<int>
     */
    public function nodes(array $taggedNodeIds, mixed $mode, mixed $userNodes): array
    {
        $tagged = self::ids($taggedNodeIds);
        $selected = self::ids($userNodes);

        return match (AccessMode::parse($mode)) {
            null => $tagged,
            AccessMode::Negative => array_values(array_diff($tagged, $selected)),
            AccessMode::Additive => self::union($tagged, $selected),
            AccessMode::Overwrite => $selected,
        };
    }

    /**
     * A submitted node is accepted only when this quota has a mode and the id is in the resolved list. With no mode the panel chooses, and a posted node id is refused.
     *
     * @param  list<int>  $resolvedNodeIds
     */
    public function placement(mixed $submittedNode, array $resolvedNodeIds, mixed $mode): NodePlacement
    {
        $resolved = self::ids($resolvedNodeIds);

        if (AccessMode::parse($mode) === null) {
            return $submittedNode === null || $submittedNode === ''
                ? NodePlacement::automatic($resolved)
                : NodePlacement::reject();
        }

        $nodeId = PositiveId::parse($submittedNode);

        if ($nodeId === null || !in_array($nodeId, $resolved, true)) {
            return NodePlacement::reject();
        }

        return NodePlacement::only($nodeId);
    }

    /**
     * @param  list<string>  $requiredTags  An empty list allows every public node, matching FindViableNodesService.
     * @param  list<string>  $nodeTags
     */
    public function tagsAllow(array $requiredTags, array $nodeTags): bool
    {
        if ($requiredTags === []) {
            return true;
        }

        return array_intersect($requiredTags, $nodeTags) !== [];
    }

    /**
     * @return list<int>
     */
    public static function ids(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $item) {
            $id = PositiveId::parse($item);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int>  $left
     * @param  list<int>  $right
     * @return list<int>
     */
    private static function union(array $left, array $right): array
    {
        return array_values(array_unique([...$left, ...$right]));
    }
}
