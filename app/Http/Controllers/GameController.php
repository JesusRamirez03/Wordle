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
        // Verificar si el parámetro guess está presente
        if (empty($guess)) {
            return response()->json(['message' => 'La palabra adivinada es obligatoria.'], 400);
        }
    
        $user = auth()->user();
        
        // Verificar si la cuenta está activa
        if (!$user->is_active) {
            return response()->json(['message' => 'No puedes jugar porque tu cuenta está desactivada.'], 403);
        }
        
        // Buscar el juego con el gameId proporcionado
        $game = Game::findOrFail($gameId);
    
        if ($game->user_id !== $user->id) {
            return response()->json(['message' => 'No tienes permiso para participar en esta partida.'], 403);
        }
    
        if ($game->status !== 'playing') {
            return response()->json(['message' => 'El juego ya ha terminado.'], 400);
        }
    
        // Verificar si la palabra ya fue ingresada
        $guessedWords = json_decode($game->guessed_words, true) ?? [];
        if (in_array($guess, array_column($guessedWords, 'guess'))) {
            return response()->json([
                'message' => 'Ya intentaste esta palabra. Intenta con una diferente.',
            ], 400);
        }
    
        if (strlen($guess) !== strlen($game->word)) {
            return response()->json([
                'message' => 'La longitud de la palabra ingresada no coincide con la palabra a adivinar.',
            ], 400);
        }
    
        $feedback = [];
        $wordArray = str_split($game->word);
        $guessArray = str_split($guess);
    
        foreach ($guessArray as $index => $letter) {
            if ($letter === $wordArray[$index]) {
                $feedback[] = ['letter' => $letter, 'status' => 'La letra es correcta y está en el lugar correcto.'];
            } elseif (in_array($letter, $wordArray)) {
                $feedback[] = ['letter' => $letter, 'status' => 'La letra está en la palabra pero en el lugar equivocado.'];
            } else {
                $feedback[] = ['letter' => $letter, 'status' => 'La letra no está en ningún lugar de la palabra.'];
            }
        }
    
        $game->guessed_words = json_encode(array_merge(
            $guessedWords,
            [['guess' => $guess]]
        ));
    
        $game->remaining_attempts -= 1;
    
        if ($guess === $game->word) {
            $game->status = 'won';
    
            SendGameSummaryJob::dispatch($game)->delay(now()->addMinute());
    
            $this->sendTwilioMessage($user->phone, '¡Felicidades! Has ganado la partida.');
        } elseif ($game->remaining_attempts === 0) {
            $game->status = 'lost';
    
            SendGameSummaryJob::dispatch($game)->delay(now()->addMinute());
    
            $this->sendTwilioMessage($user->phone, 'Lo siento, has perdido la partida. La palabra era: ' . $game->word);
    
            $game->save();
    
            return response()->json([
                'message' => 'Lo siento, has perdido la partida. La palabra era: ' . $game->word,
                'feedback' => $feedback,
                'remaining_attempts' => 0,
                'status' => 'lost',
            ]);
        }
    
        $game->save();
    
        return response()->json([
            'feedback' => $feedback,
            'remaining_attempts' => $game->remaining_attempts,
            'status' => $game->status,
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
    
        $guessedWords = !empty($game->guessed_words) ? json_decode($game->guessed_words, true) : [];
    
        $guessedWordsList = array_column($guessedWords, 'guess'); 
    
        return response()->json([
            'game_id' => $game->id,
            'game_name' => $game->name,
            'word_length' => strlen($game->word),
            'remaining_attempts' => $game->remaining_attempts, 
            'guessed_words' => $guessedWordsList, 
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
