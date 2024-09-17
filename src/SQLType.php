<?php

namespace AyelaORM;


#[\Attribute(\Attribute::TARGET_PROPERTY)]
class SQLType
{
    public string $type;

    public function __construct(string $type)
    {
        $this->type = $type;
    }
}


?>