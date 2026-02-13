<?php

declare(strict_types=1);

namespace OpenScene\Engine\Http;

final class Shortcode
{
    public function hooks(): void
    {
        add_shortcode('openscene_app', [$this, 'render']);
    }

    public function render(): string
    {
        $context = [
            'route' => 'page',
            'communitySlug' => null,
            'postId' => null,
            'username' => null,
        ];

        return sprintf(
            '<div id="openscene-root" data-openscene-context="%s"></div>',
            esc_attr((string) wp_json_encode($context))
        );
    }
}
