<?php

namespace App\Jobs;

use App\AppStatics;
use App\Models\Call;
use App\Models\CallRecordFile;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use League\Csv\Statement;
use Carbon\Carbon;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;

class ProcessCallRecords implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;


    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {

    }

    /**
     * Execute the job.
     *
     * Runs the record file processing. It assumes that the download process finished successfully.
     * Tries to find the file in the pre-defined path and if doesn't exists it stops.
     * If the file is found the process begins and a db entry for the record file is used to set the 
     * line marker on where to resume the processing. last_processed_line attribute keeps track of this and is 
     * updated only when a record is added in the db. last_processed_bpd_call_is is also updated every time
     * a record is added. 
     * 
     * In the process records with empty or null bpd_call_id are skipped. In addition if a record exists in the db it is also skipped.
     * 
     * Note: The current implementation is set to fetch only the 2018 records due to time limitations and Heroku dyno scalability issues
     * with long running processes in their Free plan. A database of size bigger than 5MB is also needed since it will take about 30,000 
     * records otherwise.
     * 
     *
     * @return void
     */
    public function handle()
    {
        Log::info('Processing call records file...');

        $exists = false;
        $file_path = NULL;
        $call_records_file = NULL;

        if (App::environment('local')) { //for local server testing
            $exists = Storage::disk('local')->exists(AppStatics::$CALL_RECORDS_FILENAME_MINI);
            if ($exists){
                $call_records_file = CallRecordFile::where('uri', 'storage/app/' . AppStatics::$CALL_RECORDS_FILENAME_MINI)->first();
                if (!$call_records_file){ // if it doesn't exist
                    $call_records_file = new CallRecordFile;
                    $call_records_file->setUri('storage/app/' . AppStatics::$CALL_RECORDS_FILENAME_MINI);
                    $call_records_file->save();
                }
            }
            $file_path = 'storage/app/' . AppStatics::$CALL_RECORDS_FILENAME_MINI;
        } else { // checking if db entry for the file exists. Need to know uri of file and last processed line.
            Log::info("Fetching latest call records file db entry.");
            $call_records_file = CallRecordFile::latest()->first();
            if ($call_records_file){
                Log::info("DB Entry found for the latest call records file.");
                $exists = Storage::disk('local')->exists(AppStatics::$CALL_RECORDS_FILENAME);
                $file_path = $call_records_file->uri;
            } else {
                $exists = false;
                Log::info('No DB entry found for the latest downloaded call records file');
            }
        }

        if (!$exists){ //records file does not exist. something is wrong with the download process.
            Log::info('Records file does not exist.');
        } else { //records file is in filesystem. 
            //using the db entry for record file to determine if the process has been run before and resume from what is left of the lines.

            $last_processed_line = $call_records_file->getLastProcessedLine();
            $last_bpd_call_id = NULL;

            if ($last_processed_line == NULL)
                $last_processed_line = 0;

            Log::info('Starting from last processed line #: ' . $last_processed_line);

            $bpd_call_id = 'null';
            $call_time = 'null';
            $priority = -1;
            $district = 'null';
            $description = 'null';
            $address = 'null';
            $latitude = 0;
            $longitude = 0;

            $record_count = 0;
            $records_added = 0;
            $records_skipped = 0;
            $records_failed_to_add = 0;


            $reader = Reader::createFromPath($call_records_file->uri, 'r');
            $reader->setHeaderOffset(0);
            $stmt = (new Statement())->offset($last_processed_line);
            $records = $stmt->process($reader);

            $total_lines = count($reader);

            $total_lines_left = $total_lines - $last_processed_line;

            if ($total_lines_left < 0)
                $total_lines_left = 0;

            $output = new ConsoleOutput();
            $progress = new ProgressBar($output, $total_lines_left);
            $progress->start();

            foreach ($records as $offset => $record) {

                $record_count++;

                //all cell values will either exist or mapped to null by the reader
                $bpd_call_id = $record['callNumber'];
                $call_time = $record['callDateTime'];
                $priority = $record['priority'];
                $district = $record['district'];
                $description = $record['description'];
                $address = $record['incidentLocation'];
                $addrAndCoordinates = $record['location'];

                if ($bpd_call_id == 'null' || empty($bpd_call_id) || !Carbon::parse($call_time)->isCurrentYear()){
                    // Skip this one
                    $progress->advance();
                    $records_skipped++;
                    continue;
                }

                $record_exists = Call::where('bpd_call_id', $bpd_call_id)->first();

                if ($record_exists){
                    // Skip this one
                    $progress->advance();
                    $records_skipped++;
                    continue;
                }

                if ($call_time == 'null' || empty($call_time))
                    $call_time = '0000-00-00 00:00:00';
                else {
                    $call_time = Carbon::parse($call_time)->toDateTimeString();
                }

                if ($priority == 'null' || empty($priority))
                    $priority = Call::$PRIORITY_UNKNOWN;
                else {
                    switch ($record['priority']){

                        case Call::$STRING_PRIORITY_NON_EMERGENCY: $priority = Call::$PRIORITY_NON_EMERGENCY; break;
                        case Call::$STRING_PRIORITY_LOW: $priority = Call::$PRIORITY_LOW; break;
                        case Call::$STRING_PRIORITY_MEDIUM: $priority = Call::$PRIORITY_MEDIUM; break;
                        case Call::$STRING_PRIORITY_HIGH: $priority = Call::$PRIORITY_HIGH; break;
                        default : $priority = Call::$PRIORITY_UNKNOWN;
                    }
                }

                if ($district == 'null' || empty($district))
                    $district = AppStatics::$UNKNOWN_STRING;

                if ($description == 'null' || empty($description))
                    $description = AppStatics::$UNKNOWN_STRING;

                if ($address == 'null' || empty($address))
                    $address = AppStatics::$UNKNOWN_STRING;

                if ($addrAndCoordinates == 'null' || empty($addrAndCoordinates)){
                    $latitude = 0;
                    $longitude = 0;
                } else {
                    $temp = str_after($addrAndCoordinates, "(");

                    $coordinates = str_replace(")", "", $temp);
                    $coordinates = str_replace(" ", "", $coordinates);

                    $coordinates_array = explode(",", $coordinates);

                    //Setting lat. and long.
                    if (count($coordinates_array) == 2){
                        $latitude = $coordinates_array[0];
                        $longitude = $coordinates_array[1];
                    }

                    if (!is_numeric($latitude) || !is_numeric($longitude)){
                        $latitude = 0;
                        $longitude = 0;
                    }
                }

                $call = new Call;
                $call->setBpdCallId($bpd_call_id);
                $call->setCallTime($call_time);
                $call->setPriority($priority);
                $call->setDistrict($district);
                $call->setDescription($description);
                $call->setAddress($address);
                $call->setLatitude($latitude);
                $call->setLongitude($longitude);
                $success = $call->save();

                if (!$success){
                    $records_failed_to_add++;
                } else {
                    $records_added++;
                    $last_bpd_call_id = $bpd_call_id;
                    $call_records_file->setLastProcessedLine($record_count);
                    $call_records_file->setLastProcessedBPDCallId($last_bpd_call_id);
                    $call_records_file->save();
                }

                $progress->advance();

            }

            $progress->finish();

            if ($call_records_file->getLastProcessedLine() == count($reader)){
                Log::info('Database has the latest call records. Processing skipped.');
            }

            Log::info('Processing complete.');
            Log::info('Record count: ' . $record_count);
            Log::info('Records added: ' . $records_added);
            Log::info('Records skipped: ' . $records_skipped);
            Log::info('Records failed to add: ' . $records_failed_to_add);
        }

    }
}
