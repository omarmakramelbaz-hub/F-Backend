<?php

namespace App\Support;

use Illuminate\Support\HtmlString;
use InvalidArgumentException;

/** The original admin views use only named-route DELETE form wrappers. */
final class NativeDeleteForm
{
    public static function open(array $options): HtmlString
    {
        if (array_diff(array_keys($options), ['method', 'route', 'style'])
            || !is_string($options['method'] ?? null)
            || strtoupper($options['method']) !== 'DELETE'
            || !is_array($options['route'] ?? null)
            || !is_string($options['route'][0] ?? null)
            || $options['route'][0] === ''
            || (array_key_exists('style', $options) && !is_string($options['style']))) {
            throw new InvalidArgumentException('Only the original named-route DELETE form options are supported.');
        }

        $namedRoute = $options['route'];
        // Preserve named query parameters and the original single-ID/model URL
        // binding instead of flattening category/account filters into path IDs.
        $parameters = array_slice($namedRoute, 1);
        if (array_keys($namedRoute) === [0, 1]) {
            $parameters = $parameters[0];
        }

        $attributes = ' method="POST" action="'.e(route($namedRoute[0], $parameters), false).'" accept-charset="UTF-8"';
        if (array_key_exists('style', $options)) {
            $attributes .= ' style="'.e($options['style'], false).'"';
        }

        return new HtmlString('<form'.$attributes.'>'.method_field('DELETE').csrf_field());
    }

    public static function close(): HtmlString
    {
        return new HtmlString('</form>');
    }
}
