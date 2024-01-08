<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Log;
use JWTAuth;
use App\Http\Controllers\Controller;
use App\Aspects\Transactional;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\Group;
//use App\Aspects\Transactional;
use Illuminate\Support\Facades\Cache;
use Validator;
use App\Models\MyFile;
use DB;
use AhmadVoid\SimpleAOP\Aspect;
class FileController extends Controller
 {

    public function showLog()
    {
        $logContent = file_get_contents(storage_path('logs/a'));

        // استرداد المحتويات كاستجابة JSON
        return response()->json($logContent);

        // أو استرداد المحتويات كاستجابة HTML
        // return response()->html($logContent);
    }

public function upload(Request $request)
{
    // Query to find the current user
    $user = auth()->user();

    if (!$user) {
        return response()->json("User not authenticated", 401);
    }

    // Query to find the group based on the ID
    $group = Group::find($request->groupId);

    if (!$group) {
        return response()->json("Group not found", 404);
    }

    // Check if the user has access to the group
    if (!$user->groups->contains($group)) {
        return response()->json("You do not have access to this group", 403);
    }
    $file = $request->file('link');
    $fileName = time().$file->getClientOriginalName();
    $path = $file->storePubliclyAs('public/upload', $fileName);
    $link = Storage::url($path);
    $file = new MyFile;
    $file->link = $link;
    $file->file_name = $fileName;
    $file->status = $request->status;
    $file->group_id = $group->id;
    $result=$file->save();
    Log::channel('a')->info('تم رفع الملف بنجاح: ' . $result);
    $file->users()->attach($user->id, ['role' => 'owner', 'file_id' => $file->id]);

     if($result)
      {
        return ["Result"=>"file has been uploaded"];
      }
     return ["Result"=>"operation failed"];

}

public function reserveFile($groupId, $fileId)
{
    $user = auth()->user();

    if (!$user) {
        return response()->json("User not authenticated", 401);
    }

    $group = Group::find($groupId);

    if (!$group) {
        return response()->json("Group not found", 404);
    }

    if (!$user->groups->contains($group)) {
        return response()->json("You do not have access to this group", 403);
    }

    $file = MyFile::find($fileId);

    if (!$file) {
        return response()->json(['message' => 'File not found'], 404);
    }

    if ($file->status == 'reserved') {
        return response()->json(['message' => 'File already reserved'], 400);
    }

    $file->status = 'reserved';
    $result=$file->save();
    Log::channel('a')->info('تم حجز الملف بنجاح: ' . $result);
    if ($result) {
       // return ["Result" => "File has been reserved"];
          // Download the file
          $file_path = storage_path("app/public/upload/{$file->file_name}");
          $file->users()->attach($user->id, ['role' => 'reserver', 'file_id' => $file->id]);
          // Return the file as a response
          return response()->download($file_path, $file->file_name);
    }
    return response()->json(["Result" => "Operation failed"]);
}





    public function freeFile($groupId, $fileId, Request $request)
{
    $user = auth()->user();

    if (!$user) {
        return response()->json("User not authenticated", 401);
    }

    $group = Group::find($groupId);

    if (!$group) {
        return response()->json("Group not found", 404);
    }

    if (!$user->groups->contains($group)) {
        return response()->json("You do not have access to this group", 403);
    }

    $file = MyFile::find($fileId);

    if (!$file) {
        return response()->json(['message' => 'File was not found'], 400);
    }

    if ($file->status == 'free') {
        return response()->json(['message' => 'File already free!! Do you want to reserve it?'], 400);
    }

    $file1 = $request->file('link');
    $fileName = time() . $file1->getClientOriginalName();
    $path = $file1->storePubliclyAs('public/upload', $fileName);
    $link = Storage::url($path);

    $lastReservation = $file->users()->where('role', 'reserver')->latest()->first();

    if ($lastReservation && $lastReservation->pivot->user_id == $user->id) {
        // Update file information
        $file->link = $link;
        $file->file_name = $fileName;
        $file->status = 'free';
        $result = $file->save();
//        Log::info('تم إلغاء حجز الملف بنجاح: ' . $result);
        Log::channel('a')->info('م إلغاء حجز الملف بنجاح:: ' . $result);


        // Attach a new record indicating that the file is free
        $file->users()->attach($user->id, ['role' => 'free']);

        if ($result) {
            return response()->json(['message' => 'File has been made free by the same user who reserved it'], 200);
        } else {
            return response()->json(['message' => 'Operation failed'], 400);
        }
    } else {
        return response()->json(['message' => 'File not reserved by the current user'], 400);
    }
}



   public function indexFiles()
  {

   return MyFile::all();

  }







  public function reserveFiles(Request $request,$groupId)
{
    $user = auth()->user();

    if (!$user) {
        return response()->json("User not authenticated", 401);
    }
 $id1=$user->id;
    $group = Group::find($groupId);

    if (!$group) {
        return response()->json("Group not found", 404);
    }

    if (!$user->groups->contains($group)) {
        return response()->json("You do not have access to this group", 403);
    }

    $data = $request->all();
    $files = $data["files"];
    if (empty($files))
    {
        return response()->json(['message' => 'No files provided']);
    }
    $response = ['message' => 'operation failed'];
    $allFilesFree = true;
    foreach ($files as $file) {
        $status = MyFile::where('id', '=', $file["id"])->value('status');
        if ($status === "reserved") {
            $allFilesFree = false;
            break;
        }
    }
     if ($allFilesFree) {
        foreach ($files as $file) {
            $fileModel = MyFile::find($file["id"]);
            $fileModel->status = 'reserved';
            $result = $fileModel->save();
            Log::channel('a')->info('تم حجز الملف بنجاح: ' . $result);

            $fileModel->users()->attach($id1, ['role' => 'reserver', 'file_id' => $fileModel->id]);

            if ($result) {
                $file = Storage::disk('public')->path("upload/{$fileModel->file_name}");
                 response()->download($file, $fileModel->file_name);
                 return ["Result" => "All files reserved successfully"];

            }
        }
    } else {
        return ["Result" => "Some files are already reserved"];
    }
}




 public function showFiles($groupId)
 {
        $user = auth()->user();

    if (!$user) {
        return response()->json("User not authenticated", 401);
    }

    // Query to find the group based on the ID
    $group = Group::find($groupId);


    if (!$group) {
        return response()->json("Group not found", 404);
    }

    // Check if the user has access to the group
    if (!$user->groups->contains($group)) {
        return response()->json("You do not have access to this group", 403);
    }

         $files = $group->files;

         return response()->json($files);
     }






     public function readFile($fileId)
  {
    // Query to find the current user
    $user = auth()->user();

    if (!$user) {
        return response()->json("User not authenticated", 401);
    }

    // Query to find the file based on the ID
    $file = MyFile::find($fileId);

    if (!$file) {
        return response()->json("File not found", 404);
    }

    // Query to find the group based on the file
    $group = $file->group;

    // Check if the user has access to the group
    if (!$user->groups->contains($group)) {
        return response()->json("You do not have access to this file", 403);
    }

    // Check if the file is reserved
    if ($file->status == 'reserved') {
        return response()->json("Cannot read a reserved file", 422);
    }

    // Construct the file path
    $filePath = storage_path("app/public/upload/{$file->file_name}");

    // Return the file as a response
    return response()->file($filePath);
}



// public function ReportByUserId($groupId,$fileId)
// {
//     $user = auth()->user();

//     if (!$user) {
//         return response()->json("User not authenticated", 401);
//     }

//     // Query to find the group based on the ID
//     $group = Group::find($groupId);
//     if (!$group) {
//         return response()->json("Group not found", 404);
//     }

//     // Check if the user has access to the group
//     if (!$user->groups->contains($group)) {
//         return response()->json("You do not have access to this group", 403);
//     }

//     $file = MyFile::find($fileId);

//     if ($file) {
//         $userId = $user->id; // or any other user ID
//         $report = $file->getReportByUserId($userId);

//         // Process the report data as needed
//         return response()->json(['report' => $report]);
//     } else {
//         return response()->json(['message' => 'File not found'], 404);
//     }
//     }


public function ReportByUserId($userId)
    {
        $user = User::find($userId);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $report = $user->files()
          //  ->with(['myFile'])
            ->select( 'my_files.*') // Add other columns you want to select
            ->get();

        return response()->json(['report' => $report]);
    }




    public function ReportByFileId($fileId)
    {
        $Myfile = MyFile::find($fileId);

        if (!$Myfile) {
            return response()->json(['message' => 'this file not found'], 404);
        }

        $report = $Myfile->users()
          //  ->with(['myFile'])
            ->select( 'users.*') // Add other columns you want to select
            ->get();

        return response()->json(['report' => $report]);
    }

 }





