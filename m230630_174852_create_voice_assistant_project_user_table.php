<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%voice_assistant_project_user}}`.
 */
class m230630_174852_create_voice_assistant_project_user_table extends Migration
{
    private $table = '{{%voice_assistant_project_user}}';

    public function safeUp()
    {
        $this->createTable($this->table, [
            'id' => $this->primaryKey(),
            'budget_id' => $this->integer()->notNull()->comment('id бюджета'),
            'email' => $this->string(255)->notNull()->comment('email пользователя'),
            'name' => $this->string(255)->comment('имя пользователя')
        ]);

        $this->createIndex(
            '{{%idx-voice_assistant_project_user-budget_id-email}}',
            $this->table,
            'budget_id, email',
            true
        );

        $this->addForeignKey(
            'budget_id_fk',
            $this->table,
            'budget_id',
            '{{%budgets}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropTable($this->table);
    }
}
