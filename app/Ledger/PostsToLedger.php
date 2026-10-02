<?php

namespace App\Ledger;

/**
 * Implemented by any source document (invoice, GRN, production receipt,
 * payroll run, consignment expense, …) so it can be handed to the
 * PostingEngine. This is the contract that keeps accounting the backbone:
 * a document describes what happened; the engine decides how it hits the books.
 */
interface PostsToLedger
{
    public function toLedgerEntry(): LedgerEntry;
}
