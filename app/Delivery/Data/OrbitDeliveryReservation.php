<?php

declare(strict_types=1);

namespace App\Delivery\Data;

final class OrbitDeliveryReservation
{
    /** @var resource|null */
    private mixed $handle;

    /** @param resource $handle */
    public function __construct(
        mixed $handle,
        public readonly string $path,
    ) {
        $this->handle = $handle;
    }

    public function release(): void
    {
        if (! is_resource($this->handle)) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
