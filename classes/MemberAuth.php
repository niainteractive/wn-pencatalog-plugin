<?php namespace Niainteractive\PenCatalog\Classes;

/**
 * Resolves the signed-in member without assuming an auth package is installed.
 *
 * Member lists (wish list, my pens, gift list) are optional: they need the
 * Winter.User plugin, which registers the `Auth` alias and the `getUser()`
 * method the plugin used to call directly. On a site without that plugin the
 * alias does not exist, so a direct \Auth::getUser() is a fatal error — enough
 * to take the whole catalogue down. Every lookup here returns null instead.
 *
 * Site builders who install Winter.User get member features automatically;
 * everyone else gets a catalogue that renders.
 */
class MemberAuth
{
    /**
     * @return \Winter\User\Models\User|null
     */
    public static function user()
    {
        try {
            if (!class_exists('Auth')) {
                return null;
            }

            return \Auth::getUser();
        }
        catch (\Throwable $e) {
            return null;
        }
    }

    public static function id()
    {
        $user = static::user();

        return $user ? $user->id : null;
    }

    public static function check()
    {
        return (bool) static::user();
    }
}
