<?php

namespace Nermif;

trait IdSanitizer
{

    /**
     * 验证 ID 是否安全（不允许包含冒号、空格、换行等，防止 Redis Key 混淆）
     *
     * @param string $id
     * @return bool
     */
    protected function isValidId(string $id): bool
    {
        return strlen($id) > 0 && preg_match('/^[a-zA-Z0-9_\-]+$/', $id);
    }
}