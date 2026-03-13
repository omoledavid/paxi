<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use App\Mail\NewFeedbackNotification;

class FeedbackController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'subject' => 'required|string|max:255',
            'message' => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $feedback = Feedback::create([
            'user_id' => $request->user()->sId,
            'subject' => $request->subject,
            'message' => $request->message,
            'status' => 'pending',
        ]);

        // Get admin email from sitesettings
        $adminEmail = DB::table('sitesettings')->value('email');
        
        if ($adminEmail) {
            Mail::to($adminEmail)->send(new NewFeedbackNotification($feedback, $request->user()));
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Feedback submitted successfully. We will review it shortly.',
            'data' => $feedback,
        ], 201);
    }

    public function index(Request $request)
    {
        $feedbacks = Feedback::with('user:sId,sFname,sLname,sEmail,sPhone')
            ->where('user_id', $request->user()->sId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $feedbacks,
        ]);
    }

    public function show(Request $request, $id)
    {
        $feedback = Feedback::with('user:sId,sFname,sLname,sEmail,sPhone')
            ->where('user_id', $request->user()->sId)
            ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $feedback,
        ]);
    }
}
