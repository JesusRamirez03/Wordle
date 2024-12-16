<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Env;
use Twilio\Rest\Client;
use App\Jobs\SendGameSummaryJob;


class GameController extends Controller
{
    public function createGame(Request $request)
    {
        $user = auth()->user();
    
        // Verificar si la cuenta está activa
        if (!$user->is_active) {
            return response()->json(['message' => 'No puedes crear un juego porque tu cuenta está desactivada.'], 403);
        }
    
        $word = $this->getRandomWord(); // Obtener la palabra al azar

        $game = Game::create([
            'user_id' => $user->id,
            'name' => "Juego de {$user->name}",
            'word' => $word,
            'remaining_attempts' => env('MAX_ATTEMPTS', 5), // Intentos disponibles
            'guessed_letters' => json_encode([]), // Letras adivinadas inicialmente vacías
            'status' => 'playing', // Estado inicial
        ]);
    
        return response()->json([
            'game' => [
                'id' => $game->id,
                'user_id' => $game->user_id,
                'name' => $game->name,
                'word_length' => strlen($word),
                'remaining_attempts' => $game->remaining_attempts,
                'masked_word' => $this->getMaskedWord($word, []), // Mostrar palabra oculta
            ]
        ], 201);
    }
    
    

    public function guess(Request $request, $gameId, $guess)
    {
        // Validar que la letra es una sola
        if (strlen($guess) !== 1 || !ctype_alpha($guess)) {
            return response()->json(['message' => 'Solo se permite adivinar una letra válida.'], 400);
        }
    
        $user = auth()->user();
    
        // Verificar si la cuenta está activa
        if (!$user->is_active) {
            return response()->json(['message' => 'No puedes jugar porque tu cuenta está desactivada.'], 403);
        }
    
        $game = Game::findOrFail($gameId);
    
        if ($game->user_id !== $user->id) {
            return response()->json(['message' => 'No tienes permiso para participar en esta partida.'], 403);
        }
    
        if ($game->status !== 'playing') {
            return response()->json(['message' => 'El juego ya ha terminado.'], 400);
        }
    
        // Obtener las letras adivinadas
        $guessedLetters = json_decode($game->guessed_letters, true) ?? [];
        if (in_array($guess, $guessedLetters)) {
            return response()->json([
                'message' => 'Ya intentaste esta letra. Intenta con una diferente.',
            ], 400);
        }
    
        // Agregar la letra adivinada a las letras ya adivinadas
        $guessedLetters[] = $guess;
        $game->guessed_letters = json_encode($guessedLetters);
    
        // Verificar si la letra está en la palabra
        if (strpos($game->word, $guess) !== false) {
            $feedback = '¡Correcto! La letra está en la palabra.';
        } else {
            $game->remaining_attempts -= 1;
            $feedback = 'Incorrecto. La letra no está en la palabra.';
        }
    
        // Verificar si el jugador ha ganado o perdido
        $maskedWord = $this->getMaskedWord($game->word, $guessedLetters);
        if ($maskedWord === $game->word) {
            $game->status = 'won';
            SendGameSummaryJob::dispatch($game)->delay(now()->addMinute());
            $this->sendTwilioMessage($user->phone, '¡Felicidades! Has ganado la partida.');
        } elseif ($game->remaining_attempts === 0) {
            $game->status = 'lost';
            SendGameSummaryJob::dispatch($game)->delay(now()->addMinute());
            $this->sendTwilioMessage($user->phone, 'Lo siento, has perdido la partida. La palabra era: ' . $game->word);
        }
    
        $game->save();
    
        return response()->json([
            'feedback' => $feedback,
            'remaining_attempts' => $game->remaining_attempts,
            'status' => $game->status,
            'masked_word' => $maskedWord,
        ]);
    }
    
        
    public function show($gameId)
    {
        $user = auth()->user();
        
        if (!$user->is_active) {
            return response()->json(['error' => 'Tu cuenta está inactiva.'], 403);
        }
    
        $game = Game::find($gameId);
    
        if (!$game) {
            return response()->json(['error' => 'Juego no encontrado.'], 404);
        }
    
        if ($game->status !== 'playing') {
            return response()->json(['error' => 'Juego terminado.'], 403);
        }
    
        if ($game->user_id !== $user->id && $user->role !== 'admin') {
            return response()->json(['error' => 'No estás autorizado para ver este juego.'], 403);
        }
    
        $guessedLetters = !empty($game->guessed_letters) ? json_decode($game->guessed_letters, true) : [];
    
        return response()->json([
            'game_id' => $game->id,
            'game_name' => $game->name,
            'word_length' => strlen($game->word),
            'remaining_attempts' => $game->remaining_attempts,
            'masked_word' => $this->getMaskedWord($game->word, $guessedLetters),
        ]);
    }
    

    public function showHistoryById($userId)
    {
        $user = Auth::user();  
    
        if (!$user->is_active) {
            return response()->json(['error' => 'Tu cuenta está inactiva.'], 403);
        }
    
        if ($user->id != $userId && $user->role != 'admin') {
            return response()->json(['message' => 'No estás autorizado para ver el historial de este usuario.'], 403);
        }
    
        $games = Game::where('user_id', $userId)->get();
    
        if ($games->isEmpty()) {
            return response()->json(['message' => 'No se encontraron juegos para este usuario.'], 404);
        }
    
        return response()->json([
            'message' => 'Juegos recuperados exitosamente.',
            'games' => $games,
        ]);
    }
    
    
    

    public function leaveGame($gameId)
    {
        $user = auth()->user();
    
        // Verificar si el usuario está activo
        if (!$user->is_active) {
            return response()->json(['error' => 'Tu cuenta está inactiva. No puedes abandonar la partida.'], 403);
        }
    
        $game = Game::find($gameId);
    
        if (!$game) {
            return response()->json(['error' => 'Juego no encontrado.'], 404);
        }
    
        if ($game->status !== 'playing') {
            return response()->json(['error' => 'No se puede abandonar una partida que no está en curso.'], 400);
        }
    
        if ($game->user_id !== $user->id && $user->role !== 'admin') {
            return response()->json(['error' => 'No estás autorizado para abandonar esta partida.'], 403);
        }
    
        $game->status = 'lost';
        $game->save();
    
        $this->sendTwilioMessage($user->phone, 'Has abandonado la partida. Has perdido.');
    
        return response()->json([
            'message' => 'Has abandonado la partida correctamente. El juego se ha marcado como perdido.',
            'game_id' => $game->id,
            'status' => $game->status,
        ]);
    }
    

    private function getRandomWord()
    {
        $words = ['perro', 'gatos', 'jirafa', 'zorro', 'raton','automovil', 'carosa', 'computador']; 
        return $words[array_rand($words)];
    }

    private function getFeedback($guess, $word)
    {
        $feedback = [];
        $wordLetters = str_split($word);
        $guessLetters = str_split($guess);
        $usedIndexes = []; 
    
        foreach ($guessLetters as $i => $letter) {
            if ($letter === $wordLetters[$i]) {
                $feedback[] = ['letter' => $letter, 'status' => 'correct'];
                $usedIndexes[] = $i; 
            } else {
                $feedback[] = ['letter' => $letter, 'status' => ''];
            }
        }
    
        foreach ($guessLetters as $i => $letter) {
            if ($feedback[$i]['status'] === '') {
                $foundIndex = array_search($letter, $wordLetters);
                if ($foundIndex !== false && !in_array($foundIndex, $usedIndexes)) {
                    $feedback[$i]['status'] = 'misplaced';
                    $usedIndexes[] = $foundIndex;
                } else {
                    $feedback[$i]['status'] = 'incorrect';
                }
            }
        }
    
        return $feedback;
    }

    protected function getMaskedWord($word, $guessedLetters)
    {
        $maskedWord = str_split($word);  
    
        foreach ($maskedWord as $index => $letter) {
            if (!in_array($letter, $guessedLetters)) {
                $maskedWord[$index] = '_';  
            }
        }
    
        return implode('', $maskedWord);
    }

    private function isWordGuessed(Game $game)
    {
        foreach (str_split($game->word) as $letter) {
            if (!in_array($letter, str_split($game->guessed_letters))) {
                return false;
            }
        }
        return true;
    }

    private function sendTwilioMessage($to, $body)
    {
        $sid = env('TWILIO_SID'); 
        $token = env('TWILIO_AUTH_TOKEN'); 
        $from =env('TWILIO_PHONE_NUMBER');
    
        $twilio = new Client($sid, $token);

        $message = $twilio->messages->create(
            "whatsapp:+5218711015826",
            array(
                'from' =>'whatsapp:'.$from,
                'body' =>$body,
            )
        );
    
        return $message->sid; 
    }

    public function deactivateAccount($userId)
    {
        $authenticatedUser = Auth::user();
    
        if (!$authenticatedUser->isAdmin()) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }
    
        $user = User::find($userId);

        if (!$user) {
            return response()->json(['message' => 'El usuario no existe.'], 404);
        }
    
        if (!$user->is_active) {
            return response()->json(['message' => 'La cuenta ya está desactivada.'], 400);
        }
    
        $user->is_active = false;
        $user->current_game_id = null; 
        $user->save();
    
        return response()->json([
            'message' => 'La cuenta ha sido desactivada exitosamente.',
            'user_id' => $user->id,
            'user_name' => $user->name,
            'status' => 'inactive'
        ]);
    }
    

}
