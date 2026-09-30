<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    //
    /**
     * Freno de seguridad: las pruebas borran y recrean la base de datos.
     * Si no es SQLite en memoria (phpunit.xml), se detiene antes de tocar nada.
     * setUpTraits corre después de crear la app y antes de RefreshDatabase.
     */
    protected function setUpTraits()
    {
        if (config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:') {
            fwrite(STDERR, "\nPRUEBAS DETENIDAS: no apuntan a SQLite en memoria. Revisa phpunit.xml.\n");
            exit(1);
        }

        return parent::setUpTraits();
    }
}
