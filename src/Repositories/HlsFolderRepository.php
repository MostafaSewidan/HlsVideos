<?php

namespace HlsVideos\Repositories;

class HlsFolderRepository
{
    public static array $mainSharedIds = [];
    public static array $allSharedIds = [];

    public static function mainSharedIds(): array
    {
        return [];
    }

    public static function allSharedIds(): array
    {
        return [];
    }

    public static function checkSharedFolders($query)
    {
        return $query;
    }

    public static function mainSharedFolders($query)
    {
        return $query;
    }

    public static function masters($query)
    {
        return $query->whereNull('parent_id');
    }
    
    public static function isSharedFolders(): bool
    {
        return false;
    }
}
