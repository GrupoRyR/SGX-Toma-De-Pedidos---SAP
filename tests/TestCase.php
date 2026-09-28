<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Ninguna prueba sale a internet.
         *
         * La aplicacion llama a Microsoft Graph para el correo de rechazo y a
         * Entra ID para autenticar. Sin esto, una prueba que toque ese camino
         * se queda esperando el timeout real -- y peor, podria mandar un correo
         * de verdad. Cualquier peticion no simulada falla la prueba y dice cual.
         */
        Http::preventStrayRequests();
    }
}
