<?php

namespace App\Jobs;

use App\Models\Game;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class SendGameSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $game;

    /**
     * Create a new job instance.
     *
     * @param Game $game
     */
    public function __construct(Game $game)
    {
        $this->game = $game;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $summary = $this->generateSummary();
        $this->sendToSlack($summary);
    }

    /**
     * Generar el resumen del juego.
     *
     * @return array
     */
    protected function generateSummary()
    {
        $guessedWords = json_decode($this->game->guessed_words, true);

        return [
            'Juego' => $this->game->name,
            'Usuario' => $this->game->user->name,
            'Longitud de la palabra' => strlen($this->game->word),
            'Intentos usados' => count($guessedWords),
            'Palabras intentadas' => array_column($guessedWords, 'guess'),
            'Estado' => ucfirst($this->game->status),
        ];
    }

    /**
     * Enviar el resumen del juego a Slack.
     *
     * @param array $summary
     * @return void
     */
    protected function sendToSlack(array $summary)
    {
        $webhookUrl = env('SLACK_WEBHOOK_URL'); 

        if (!$webhookUrl) {
            \Log::error('No se ha configurado la URL del webhook de Slack.');
            return;
        }

        $message = "*Resumen del juego*\n";
        foreach ($summary as $key => $value) {
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            $message .= "*{$key}:* {$value}\n";
        }

        $response = Http::post($webhookUrl, [
            'text' => $message,
        ]);

        if ($response->failed()) {
            \Log::error('Error al enviar el resumen del juego a Slack.', ['response' => $response->body()]);
        }
    }
}
