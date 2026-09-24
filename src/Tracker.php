<?php

declare(strict_types=1);

namespace QuietMetrics;

/**
 * Ce qu'une application appelle pour mesurer : une page vue, un événement.
 *
 * Client en est l'implémentation, et la seule qui envoie. L'interface existe
 * pour que les tests puissent la remplacer : Client est final, et un test ne
 * pouvait ni le simuler ni l'étendre. Typer ses dépendances avec Tracker
 * plutôt qu'avec Client, c'est ce qui les rend remplaçables.
 */
interface Tracker
{
    /**
     * @param array{url?:string,referrer?:string,ip?:string,ua?:string,lang?:string,ts?:int,visit?:bool} $overrides
     */
    public function pageview(array $overrides = []): void;

    /**
     * @param array<string,scalar|null> $props
     * @param array{url?:string,referrer?:string,ip?:string,ua?:string,lang?:string,ts?:int,visit?:bool} $overrides
     */
    public function event(string $name, array $props = [], array $overrides = []): void;
}
