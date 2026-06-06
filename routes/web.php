<?php

use Illuminate\Support\Facades\Route;
use Platform\Inbox\Livewire\Index;
use Platform\Inbox\Livewire\Show;
use Platform\Inbox\Livewire\SnoozedIndex;
use Platform\Inbox\Livewire\SubscriptionIndex;

Route::get('/', Index::class)->name('inbox.index');
Route::get('/snoozed', SnoozedIndex::class)->name('inbox.snoozed');
Route::get('/subscriptions', SubscriptionIndex::class)->name('inbox.subscriptions');
Route::get('/items/{item}', Show::class)->name('inbox.items.show');
