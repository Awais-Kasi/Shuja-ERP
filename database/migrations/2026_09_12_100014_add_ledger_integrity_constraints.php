<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database-level guarantees for the ledger. These are PostgreSQL-specific and
 * are skipped on other drivers (e.g. SQLite used in tests, where the
 * application-level PostingEngine enforces the same invariants).
 *
 *  1. Every line is one-sided and non-negative (CHECK).
 *  2. A *posted* journal must have Σ base_debit = Σ base_credit — enforced by a
 *     DEFERRABLE constraint trigger that fires at COMMIT.
 *  3. Lines of a posted journal cannot be edited (immutability).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE journal_lines
                ADD CONSTRAINT chk_line_one_sided
                CHECK (debit >= 0 AND credit >= 0 AND (debit = 0 OR credit = 0) AND (debit + credit) > 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE journal_lines
                ADD CONSTRAINT chk_base_one_sided
                CHECK (base_debit >= 0 AND base_credit >= 0 AND (base_debit = 0 OR base_credit = 0))
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_journal_balanced() RETURNS trigger AS $$
            DECLARE
                jid bigint;
                jstatus text;
                d numeric;
                c numeric;
            BEGIN
                IF TG_TABLE_NAME = 'journals' THEN
                    jid := NEW.id;
                    jstatus := NEW.status;
                ELSE
                    jid := COALESCE(NEW.journal_id, OLD.journal_id);
                    SELECT status INTO jstatus FROM journals WHERE id = jid;
                END IF;

                IF jstatus IS DISTINCT FROM 'posted' THEN
                    RETURN NULL;
                END IF;

                SELECT COALESCE(SUM(base_debit), 0), COALESCE(SUM(base_credit), 0)
                    INTO d, c FROM journal_lines WHERE journal_id = jid;

                IF d <> c THEN
                    RAISE EXCEPTION 'Journal % is out of balance: debit %, credit %', jid, d, c;
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER trg_lines_balanced
                AFTER INSERT OR UPDATE OR DELETE ON journal_lines
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION assert_journal_balanced();
        SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER trg_journal_balanced
                AFTER INSERT OR UPDATE ON journals
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION assert_journal_balanced();
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_posted_line_update() RETURNS trigger AS $$
            DECLARE
                jstatus text;
            BEGIN
                SELECT status INTO jstatus FROM journals WHERE id = OLD.journal_id;
                IF jstatus = 'posted' THEN
                    RAISE EXCEPTION 'Lines of posted journal % are immutable', OLD.journal_id;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_prevent_posted_line_update
                BEFORE UPDATE ON journal_lines
                FOR EACH ROW EXECUTE FUNCTION prevent_posted_line_update();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS trg_prevent_posted_line_update ON journal_lines');
        DB::statement('DROP TRIGGER IF EXISTS trg_lines_balanced ON journal_lines');
        DB::statement('DROP TRIGGER IF EXISTS trg_journal_balanced ON journals');
        DB::statement('DROP FUNCTION IF EXISTS prevent_posted_line_update()');
        DB::statement('DROP FUNCTION IF EXISTS assert_journal_balanced()');
        DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS chk_base_one_sided');
        DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS chk_line_one_sided');
    }
};
