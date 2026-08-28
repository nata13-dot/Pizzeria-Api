<?php

namespace App\Exceptions;

use RuntimeException;

class StockShortageException extends RuntimeException
{
    public function __construct(public readonly array $warnings)
    {
        parent::__construct('No hay inventario suficiente para enviar el pedido a cocina.');
    }
}
