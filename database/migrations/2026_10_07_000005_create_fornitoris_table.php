<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anagrafica agenti / collaboratori (Fornitori).
 *
 * In produzione la tabella esiste già (creata a mano, con chiavi esterne verso `companies` con id UUID e
 * `fornitoriroles` che in questo database non ci sono): la migrazione non fa nulla se la trova.
 * Serve a creare la stessa struttura, senza chiavi esterne, in sviluppo e nei test.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fornitoris')) {
            return;
        }

        Schema::create('fornitoris', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('name')->nullable();
            $table->string('nome')->nullable()->comment('Nome del referente');
            $table->date('stipulated_at')->nullable();
            $table->string('pec')->nullable();
            $table->string('description')->nullable();
            $table->string('email_private')->nullable();
            $table->enum('supervisor_type', ['no', 'si', 'filiale'])->default('no');
            $table->string('oam', 30)->nullable();
            $table->date('oam_at')->nullable();
            $table->string('oam_name')->nullable();
            $table->string('numero_iscrizione_rui', 50)->nullable();
            $table->string('ivass', 30)->nullable();
            $table->date('ivass_at')->nullable();
            $table->date('dismissed_at')->nullable();
            $table->string('ivass_name')->nullable();
            $table->enum('ivass_section', ['A', 'B', 'C', 'D', 'E'])->nullable();
            $table->string('type', 30)->nullable()->comment('Agente / Mediatore / Consulente / Call Center');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_art108')->default(false);
            $table->unsignedInteger('company_branch_id')->nullable();
            $table->unsignedInteger('coordinated_type')->nullable();
            $table->unsignedInteger('coordinated_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->date('oam_dismissed_at')->nullable();
            $table->decimal('welcome_bonus', 10, 2)->nullable();
            $table->string('campagna')->nullable();
            $table->date('available_at')->nullable();
            $table->decimal('budget', 10, 2)->nullable();
            $table->string('codice')->nullable();
            $table->string('coge')->nullable();
            $table->date('natoil')->nullable()->comment('Data di nascita');
            $table->string('indirizzo')->nullable();
            $table->string('comune')->nullable();
            $table->string('cap')->nullable();
            $table->string('prov')->nullable();
            $table->string('tel')->nullable();
            $table->string('coordinatore')->nullable();
            $table->string('piva', 20)->nullable()->index();
            $table->char('cf', 16)->nullable();
            $table->string('nomecoge')->nullable();
            $table->string('nomefattura')->nullable();
            $table->string('email')->nullable();
            $table->decimal('anticipo', 15, 2)->nullable();
            $table->enum('enasarco', ['no', 'monomandatario', 'plurimandatario', 'societa'])->nullable()->default('plurimandatario');
            $table->decimal('anticipo_residuo', 15, 2)->nullable();
            $table->decimal('contributo', 15, 2)->nullable();
            $table->string('contributo_description')->nullable()->default('Contributo spese');
            $table->string('anticipo_description')->nullable()->default('Anticipo attuale');
            $table->tinyInteger('issubfornitore')->nullable();
            $table->string('operatore')->nullable();
            $table->boolean('iscollaboratore')->nullable();
            $table->boolean('isdipendente')->nullable()->default(false);
            $table->string('regione')->nullable();
            $table->string('citta')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->char('company_id', 36)->nullable()->default('5c044917-15b3-4471-90c9-38061fcca754')->index();
            $table->smallInteger('contributoperiodicita')->nullable();
            $table->date('contributodalmese')->nullable();
            $table->integer('fornitorirole_id')->nullable()->index();
            $table->json('employee_roles')->nullable();
            $table->bigInteger('branch_id')->nullable();
        });
    }

    public function down(): void
    {
        // Non si elimina mai l'anagrafica: in produzione la tabella non è creata da questa migrazione.
    }
};
