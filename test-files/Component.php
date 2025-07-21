<?php

namespace EcommerceGeeks\LaravelInertiaTables\Components;

use Closure;
use EcommerceGeeks\LaravelInertiaTables\FromData;
use Exception;
use Inertia\Inertia;
use Inertia\Response;
use JsonSerializable;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

abstract class Component implements JsonSerializable
{
    protected string $componentName;

        /**
     * @var array<mixed>
     */
    protected array $classes = [];

    protected FromData|Closure|bool $show = true;

    protected function getClasses(): string
    {
        return implode(' ', $this->classes);
    }

        /**
     * @return array<mixed>
     */
    public function getProps(): array
    {
        return [];
    }

    public function getComponentName(): string
    {
        return $this->componentName;
    }

    public function show(FromData|Closure $show): self
    {
        $this->show = $show;

        return $this;
    }

        /**
     * @return array<mixed>
     */
    protected function serializeArray(string $array): array
    {
        $return = [];
        foreach ($array as $key => $prop) {
            if (is_array($prop)) {
                $return[$key] = $this->serializeArray($prop);
            } else {
                $return[$key] = $prop;
            }
        }

        return $return;
    }

    public function setClass(string $class): self
    {
        $this->classes[] = $class;

        return $this;
    }

    /**
     * @throws Exception
     */
    protected function validateIsStringOrComponent(string $var): void
    {
        if (! (is_string($var) || ($var instanceof Component))) {
            throw new Exception('Argument must be either a string or and instance of Component');
        }
    }

    /**
     * @param  $var  bool|float|int|string|null|Component
     *
     * @throws Exception
     */
    protected function scalarOrComponent(mixed $var): Component
    {
        if (is_scalar($var) || $var === null || $var instanceof FromData) {
            return Scalar::make($var);
        } elseif ($var instanceof Component) {
            return $var;
        }
        throw new Exception('Argument must be either a scalar, null, FromData or a Component. It is '.get_debug_type($var));
    }

        /**
     * @return array<string, array>
     */
    protected function toComponentArray(): array
    {
        return [
            'componentName' => $this->getComponentName(),
            'props' => [
                ...$this->getProps(),
                'show' => $this->show,
            ],
        ];
    }

        /**
     * @return array<mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->serializeArray($this->getProps());
    }

    /**
     * Generate the props and add these to existing Inertia (have a look at Inertia::share)
     * @param array<mixed> $props
 */
    public function render(array $props = []): Response|BinaryFileResponse
    {
        return Inertia::render($this->componentName, array_merge($props, $this->jsonSerialize()));
    }
}
