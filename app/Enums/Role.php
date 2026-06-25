<?php

declare(strict_types=1);

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Manager = 'manager';
    case TeamLead = 'team_lead';
    case Employee = 'employee';
    case Placeholder = 'placeholder';

    public function isProjectScopedViewer(): bool
    {
        return $this === self::TeamLead;
    }

    /**
     * @return list<string>
     */
    public static function projectScopedViewerValues(): array
    {
        return [self::TeamLead->value];
    }
}
