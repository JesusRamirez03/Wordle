<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Env;
use Twilio\Rest\Client;

class GameController extends Controller
{
    public function createGame(Request $request)
    {
        // Verificar que el usuario esté autenticado
        $user = Auth::user();
        
        if (!$user->is_active) {
            return response()->json(['message' => 'Tu cuenta está desactivada y no puedes crear una partida.'], 403);
        }
    
        // Verificar si el usuario ya tiene una partida activa
        $existingGame = Game::where('user_id', $user->id)
                            ->where('status', 'playing')
                            ->first();
    
        if ($existingGame) {
            return response()->json(['message' => 'Ya tienes una partida activa.'], 400);
        }
    
        // Obtener una palabra aleatoria
        $word = $this->getRandomWord(); // Método para obtener la palabra aleatoria
    
        // Crear la partida
        try {
            $game = Game::create([
                'user_id' => $user->id, // Asignamos el ID del usuario autenticado
                'word' => $word, // Asignamos la palabra aleatoria
                'guessed_words' => '[]', // Inicializamos el campo guessed_words como un arreglo vacío
                'remaining_attempts' => env('MAX_ATTEMPTS', 5), // El número de intentos en Wordle es típicamente 6
                'status' => 'playing', // El estado de la partida comienza como 'playing'
            ]);
    
            return response()->json([
                'message' => 'Partida creada exitosamente.',
                'game' => $game,
            ], 201);
        } catch (\Exception $e) {
            // Capturamos cualquier error y devolvemos una respuesta de error
            return response()->json(['message' => 'Error al crear la partida.', 'error' => $e->getMessage()], 500);
        }
    }

    

    public function guess(Request $request, $gameId)
    {
        $user = Auth::user();
        
        $request->validate([
            'guess' => 'required|string|min:5|max:5', // La palabra debe tener exactamente 5 letras
        ]);
        
        $game = Game::findOrFail($gameId);
        
        // Verificar si el jugador está participando en este juego
        if ($game->user_id !== $user->id) {
            return response()->json(['message' => 'No tienes acceso a esta partida.'], 403);
        }
    
        // Verificar que los intentos restantes sean mayores a 0
        if ($game->remaining_attempts <= 0) {
            return response()->json(['message' => 'Ya no tienes intentos restantes.'], 400);
        }
    
        // Obtener la palabra adivinada
        $guess = strtolower($request->guess); // Convertir la palabra a minúsculas
    
        // Verificar si la palabra adivinada es correcta
        if ($guess === $game->word) {
            $game->status = 'won';
            $this->sendTwilioMessage($user->phone, "¡Felicidades! Adivinaste la palabra correctamente: {$game->word}");
        } else {
            $game->remaining_attempts--;
            $feedback = $this->getFeedback($guess, $game->word); // Obtener retroalimentación sobre el intento
    
            $game->guessed_words = json_encode(array_merge(json_decode($game->guessed_words), [['guess' => $guess, 'feedback' => $feedback]]));
            
            if ($game->remaining_attempts <= 0) {
                $game->status = 'lost';
                $this->sendTwilioMessage($user->phone, "¡Perdiste! La palabra era: {$game->word}");
            } else {
                $this->sendTwilioMessage($user->phone, "Tu intento: $guess. Retroalimentación: $feedback");
            }
        }
    
        $game->save();
    
        return response()->json([
            'game' => $game,
            'remaining_attempts' => $game->remaining_attempts,
            'guessed_words' => json_decode($game->guessed_words),
        ]);
    }
    
    
    public function show($gameId)
    {
        $game = Game::find($gameId);
    
        if (!$game) {
            return response()->json(['error' => 'Juego no encontrado.'], 404);
        }
    
        if ($game->user_id !== auth()->id()) {
            return response()->json(['error' => 'No estas autorizado para ver el juego.'], 403);
        }
    
        $guessedLetters = explode(',', $game->guessed_letters); 
    
        $word = $game->word;  
    
        $wordDisplay = '';
        foreach (str_split($word) as $letter) {
            if (in_array($letter, $guessedLetters)) {
                $wordDisplay .= $letter . ' ';
            } else {
                $wordDisplay .= '_ ';
            }
        }
    
        return response()->json([
            'message' => 'Detalles del juego recuperados exitosamente.',
            'word_display' => trim($wordDisplay),  
            'guessed_letters' => $guessedLetters, 
            'attempts_left' => $game->remaining_attempts, 
        ]);
    }

    public function showHistoryById($userId)
{
    $user = Auth::user();  

    if ($user->id != $userId && !$user->is_admin) {
        return response()->json(['message' => 'No estas autorizado para ver el historial de este usuario.'], 403);
    }

    $games = Game::where('user_id', $userId)->get();

    if ($games->isEmpty()) {
        return response()->json(['message' => 'No games found for this user.'], 404);
    }

    return response()->json([
        'message' => 'Games retrieved successfully.',
        'games' => $games,
    ]);
}

    
    

    public function leaveGame($gameId)
    {
        $user = Auth::user(); 

        $game = Game::find($gameId);

        if (!$game || $game->user_id !== $user->id) {
            return response()->json(['message' => 'Game not found or you do not have access to it.'], 404);
        }

        $game->status = 'lost';
        $game->save();

        $user->current_game_id = null;
        $user->save();

        $this->sendTwilioMessage($user->phone, "¡Perdiste! Has abandonado la partida.");

        return response()->json([
            'message' => 'You have left the game and lost automatically.',
            'game' => $game,
        ]);
    }

    private function getRandomWord()
    {
        $words = ['gato', 'perro', 'elefante', 'jirafa', 'zebra'];
        return $words[array_rand($words)];
    }

    private function getFeedback($guess, $word)
{
    $feedback = [];
    
    for ($i = 0; $i < strlen($guess); $i++) {
        if ($guess[$i] === $word[$i]) {
            $feedback[] = 'correct'; // Letra correcta en su posición
        } elseif (strpos($word, $guess[$i]) !== false) {
            $feedback[] = 'misplaced'; // Letra correcta en lugar incorrecto
        } else {
            $feedback[] = 'incorrect'; // Letra incorrecta
        }
    }

    return implode(',', $feedback); // Retornar la retroalimentación como una cadena
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
    $user = User::findOrFail($userId);

    $authenticatedUser = Auth::user();
    if (!$authenticatedUser->isAdmin()) {
        return response()->json(['message' => 'You do not have permission to perform this action.'], 403);
    }

    $user->is_active = false;
    $user->current_game_id = null; 
    $user->save();

    return response()->json(['message' => 'Account has been deactivated successfully.']);
}

public function showAllHistory()
{
    $authenticatedUser = Auth::user();
    if (!$authenticatedUser->isAdmin()) {
        return response()->json(['message' => 'You do not have permission to view all game histories.'], 403);
    }

    $games = Game::all();

    if ($games->isEmpty()) {
        return response()->json(['message' => 'No games found.'], 404);
    }

    return response()->json([
        'message' => 'Games retrieved successfully.',
        'games' => $games,
    ]);
}


}
