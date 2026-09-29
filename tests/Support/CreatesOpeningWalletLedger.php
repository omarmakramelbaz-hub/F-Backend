<?php
namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesOpeningWalletLedger
{
    protected function createOpeningWalletLedger(bool $withReference = true): void
    {
        Schema::create('wallets', function (Blueprint $table) use ($withReference) {
            $table->id();
            $table->unsignedBigInteger('from_user');
            $table->unsignedBigInteger('to_user');
            $table->decimal('amount', 12, 2);
            foreach (['type', 'payment', 'status'] as $field) $table->string($field);
            if ($withReference) $table->uuid('transfer_reference')->nullable()->unique();
            $table->timestamps();
        });
    }
}
