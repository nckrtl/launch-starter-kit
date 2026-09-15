<?php

namespace App\Support\Csp\Presets;

use App\Support\NetworkOrigin;
use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Policy;
use Spatie\Csp\Preset;

class Basic implements Preset
{
    public function configure(Policy $policy): void
    {
        $configuredOrigins = config('commander.orbit.herdr_observer_origins', []);
        $observerOrigins = collect(is_array($configuredOrigins) ? $configuredOrigins : [])
            ->map(static fn (mixed $origin): ?string => NetworkOrigin::canonical($origin, 'wss'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $policy
            ->add(Directive::BASE, Keyword::SELF)
            ->add(Directive::CONNECT, [Keyword::SELF, ...$observerOrigins])
            ->add(Directive::DEFAULT, Keyword::SELF)
            ->add(Directive::FONT, Keyword::SELF)
            ->add(Directive::FORM_ACTION, Keyword::SELF)
            ->add(Directive::FRAME, Keyword::SELF)
            ->add(Directive::IMG, [Keyword::SELF, 'data:'])
            ->add(Directive::MEDIA, Keyword::SELF)
            ->add(Directive::OBJECT, Keyword::NONE)
            ->add(Directive::SCRIPT, Keyword::SELF)
            ->add(Directive::STYLE, [Keyword::SELF, Keyword::UNSAFE_INLINE])
            ->addNonce(Directive::SCRIPT);
    }
}
