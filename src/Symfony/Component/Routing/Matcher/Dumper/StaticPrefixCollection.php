<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Routing\Matcher\Dumper;

use Symfony\Component\Routing\RouteCollection;

/**
 * Prefix tree of routes preserving routes order.
 *
 * @author Frank de Jonge <info@frankdejonge.nl>
 * @author Nicolas Grekas <p@tchwork.com>
 *
 * @internal
 */
class StaticPrefixCollection
{
    private $prefix;

    /**
     * @var string[]
     */
    private $staticPrefixes = array();

    /**
     * @var string[]
     */
    private $prefixes = array();

    /**
     * @var array[]|self[]
     */
    private $items = array();

    public function __construct(string $prefix = '/')
    {
        $this->prefix = $prefix;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * @return array[]|self[]
     */
    public function getRoutes(): array
    {
        return $this->items;
    }

    /**
     * Adds a route to a group.
     *
     * @param array|self $route
     */
    public function addRoute(string $prefix, $route, string $staticPrefix = null)
    {
        if (null === $staticPrefix) {
            list($prefix, $staticPrefix) = $this->getCommonPrefix($prefix, $prefix);
        }

        for ($i = \count($this->items) - 1; 0 <= $i; --$i) {
            $item = $this->items[$i];

            list($commonPrefix, $commonStaticPrefix) = $this->getCommonPrefix($prefix, $this->prefixes[$i]);

            if ($this->prefix === $commonPrefix) {
                // the new route and a previous one have no common prefix, let's see if they are exclusive to each others

                if ($this->prefix !== $staticPrefix && $this->prefix !== $this->staticPrefixes[$i]) {
                    // the new route and the previous one have exclusive static prefixes
                    continue;
                }

                if ($this->prefix === $staticPrefix && $this->prefix === $this->staticPrefixes[$i]) {
                    // the new route and the previous one have no static prefix
                    break;
                }

                if ($this->prefixes[$i] !== $this->staticPrefixes[$i] && $this->prefix === $this->staticPrefixes[$i]) {
                    // the previous route is non-static and has no static prefix
                    break;
                }

                if ($prefix !== $staticPrefix && $this->prefix === $staticPrefix) {
                    // the new route is non-static and has no static prefix
                    break;
                }

                continue;
            }

            if ($item instanceof self && $this->prefixes[$i] === $commonPrefix) {
                // the new route is a child of a previous one, let's nest it
                $item->addRoute($prefix, $route, $staticPrefix);
            } else {
                // the new route and a previous one have a common prefix, let's merge them
                $child = new self($commonPrefix);
                list($child->prefixes[0], $child->staticPrefixes[0]) = $child->getCommonPrefix($this->prefixes[$i], $this->prefixes[$i]);
                list($child->prefixes[1], $child->staticPrefixes[1]) = $child->getCommonPrefix($prefix, $prefix);
                $child->items = array($this->items[$i], $route);

                $this->staticPrefixes[$i] = $commonStaticPrefix;
                $this->prefixes[$i] = $commonPrefix;
                $this->items[$i] = $child;
            }

            return;
        }

        // No optimised case was found, in this case we simple add the route for possible
        // grouping when new routes are added.
        $this->staticPrefixes[] = $staticPrefix;
        $this->prefixes[] = $prefix;
        $this->items[] = $route;
    }

    /**
     * Linearizes back a set of nested routes into a collection.
     */
    public function populateCollection(RouteCollection $routes): RouteCollection
    {
        foreach ($this->items as $route) {
            if ($route instanceof self) {
                $route->populateCollection($routes);
            } else {
                $routes->add(...$route);
            }
        }

        return $routes;
    }

    /**
     * Gets the full and static common prefixes between two route patterns.
     *
     * The static prefix stops at last at the first opening bracket.
     */
    private function getCommonPrefix(string $prefix, string $anotherPrefix): array
    {
        $baseLength = \strlen($this->prefix);
        $end = min(\strlen($prefix), \strlen($anotherPrefix));
        $staticLength = null;

        for ($i = $baseLength; $i < $end && $prefix[$i] === $anotherPrefix[$i]; ++$i) {
            if ('(' === $prefix[$i]) {
                $staticLength = $staticLength ?? $i;
                for ($j = 1 + $i, $n = 1; $j < $end && 0 < $n; ++$j) {
                    if ($prefix[$j] !== $anotherPrefix[$j]) {
                        break 2;
                    }
                    if ('(' === $prefix[$j]) {
                        ++$n;
                    } elseif (')' === $prefix[$j]) {
                        --$n;
                    } elseif ('\\' === $prefix[$j] && (++$j === $end || $prefix[$j] !== $anotherPrefix[$j])) {
                        --$j;
                        break;
                    }
                }
                if (0 < $n) {
                    break;
                }
                if (('?' === ($prefix[$j] ?? '') || '?' === ($anotherPrefix[$j] ?? '')) && ($prefix[$j] ?? '') !== ($anotherPrefix[$j] ?? '')) {
                    break;
                }
                $i = $j - 1;
            } elseif ('\\' === $prefix[$i] && (++$i === $end || $prefix[$i] !== $anotherPrefix[$i])) {
                --$i;
                break;
            }
        }
        if (1 < $i && '/' === $prefix[$i - 1]) {
            --$i;
        }
        if (null !== $staticLength && 1 < $staticLength && '/' === $prefix[$staticLength - 1]) {
            --$staticLength;
        }

        return array(substr($prefix, 0, $i), substr($prefix, 0, $staticLength ?? $i));
    }
}
