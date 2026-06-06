<?php

use Illuminate\Support\Facades\Route;
use Platform\Inbox\Livewire\Index;
use Platform\Inbox\Livewire\Rules\Index as RulesIndex;
use Platform\Inbox\Livewire\Rules\Show as RulesShow;
use Platform\Inbox\Livewire\Show;
use Platform\Inbox\Livewire\SnoozedIndex;
use Platform\Inbox\Livewire\SubscriptionIndex;

Route::get('/', Index::class)->name('inbox.index');
Route::get('/snoozed', SnoozedIndex::class)->name('inbox.snoozed');
Route::get('/subscriptions', SubscriptionIndex::class)->name('inbox.subscriptions');
Route::get('/rules', RulesIndex::class)->name('inbox.rules.index');
Route::get('/rules/new', RulesShow::class)->name('inbox.rules.create');
Route::get('/rules/{rule}', RulesShow::class)->name('inbox.rules.show');
Route::get('/items/{item}', Show::class)->name('inbox.items.show');
