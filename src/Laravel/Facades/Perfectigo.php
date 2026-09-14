<?php

namespace Perfectigo\Laravel\Facades;

/**
 * Registered as an alias so `Perfectigo::event(...)` works from anywhere.
 *
 * Not an Illuminate Facade: there is no instance to resolve. The underlying
 * class is all static, because the whole API is one fire-and-forget call and a
 * container binding would be ceremony around it. This subclass exists only so
 * the `Perfectigo` alias in composer.json resolves to something.
 *
 * @method static void event(string $type, ?string $email, array $data = [], ?string $key = null)
 * @method static bool configured()
 */
class Perfectigo extends \Perfectigo\Laravel\Perfectigo
{
}
