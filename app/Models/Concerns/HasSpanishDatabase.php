<?php

namespace App\Models\Concerns;

trait HasSpanishDatabase
{
    public function getAttribute($key)
    {
        $attributes = property_exists($this, 'attributeMap') ? $this->attributeMap : [];
        $values = property_exists($this, 'valueMap') ? $this->valueMap : [];
        $databaseKey = $attributes[$key] ?? $key;
        $value = parent::getAttribute($databaseKey);

        if (isset($values[$key])) {
            $reverse = array_flip($values[$key]);
            return $reverse[$value] ?? $value;
        }

        return $value;
    }

    public function setAttribute($key, $value)
    {
        $attributes = property_exists($this, 'attributeMap') ? $this->attributeMap : [];
        $values = property_exists($this, 'valueMap') ? $this->valueMap : [];
        $databaseKey = $attributes[$key] ?? $key;
        $databaseValue = $values[$key][$value] ?? $value;
        return parent::setAttribute($databaseKey, $databaseValue);
    }

    public static function databaseValue(string $attribute, mixed $value): mixed
    {
        $model = new static;
        $values = property_exists($model, 'valueMap') ? $model->valueMap : [];
        return $values[$attribute][$value] ?? $value;
    }
}
