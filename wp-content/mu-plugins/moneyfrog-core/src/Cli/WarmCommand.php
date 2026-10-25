<?php

namespace MoneyfrogCore\Cli;

use MoneyfrogCore\CardImage;

/** `wp mf warm`: computes and stores `_mf_first_img` for every published post. */
final class WarmCommand
{
    public function __invoke(array $args = [], array $assoc = []): void
    {
        $ids = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids']);
        $withImage = 0;
        $none = 0;
        foreach ($ids as $id) {
            CardImage::idFor((int) $id) > 0 ? $withImage++ : $none++;
        }
        \WP_CLI::success(sprintf('Warmed %d published posts: %d with an image, %d without.', count($ids), $withImage, $none));
    }
}
