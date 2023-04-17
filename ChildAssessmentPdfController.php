<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Child;
use App\Models\Classroom;
use App\Models\TimeTable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Models\ChildAssessment;
use Illuminate\Http\JsonResponse;
use App\Models\AssessmentCategory;
use App\Http\Resources\ChildResource;
use App\Http\Resources\SpecialistPayResource;
use App\Http\Controllers\API\SessionFairController;
use App\Http\Resources\API\ChildAssessmentResource;
use App\Http\Controllers\API\SpecialistPayController;
use App\Http\Controllers\API\ScheduleSessionController;
use App\Http\Resources\API\ScheduleSessionLightResource;
use App\Http\Controllers\API\SessionFairCollectionController;
use App\Http\Controllers\API\StatisticsController;

class ChildAssessmentPdfController extends Controller
{
    // this function opens the PDF in browser. If we want, we can downlod
    public function openPDF($id)
    {
        try {
            app()->setLocale("en");
            ini_set("pcre.backtrack_limit", "1000000000");

            $assessment = ChildAssessment::with([
                'categories.skill_category',
                'categories.skills.skill', 'categories.skills.answer', 'categories.skills.answer.point',
                'assessment', 'created_by', 'approved_by', 'child', 'child.specialists', 'child.specialists.speciality'
            ])->find($id);
            $childassessment =  new ChildAssessmentResource($assessment);


            $results = [];
            $sortTable = [];
            foreach ($childassessment->resource->categories->pluck('skills') as $key => $skills) {
                $skilssCount = count($skills);
                if ($skilssCount) {
                    $result = $skills->where('point_type', 'PowerPoint')->count() * (100 / ($skilssCount ? $skilssCount : 1));
                    // dump($skills->where('point_type', 'PowerPoint')->count() * (100 / ($skilssCount ? $skilssCount : 1)));
                    $big =  $skills->where('point_type', 'PowerPoint')->count() > $skills->where('point_type', 'WeakPoint')->count()
                    ? $skills->where('point_type', 'PowerPoint')->count() : $skills->where('point_type', 'WeakPoint')->count();
                    $sortTable[$skills[0]->child_assessment_skill_categorie_id] = $big ;
                    $results[] = ceil($result);
                }
            }
            // dd('fd');
            ksort($sortTable);

            $data = $childassessment->resource->categories->filter(function ($cat, $key) {
                return $cat->skills->count() > 0;
            })->pluck('skill_category.name.ar');

            $chart = new \ImageCharts();
            $pie = $chart
            ->cht('bvs')
            ->chxs('0,min40')
            ->chf('b0,lg,0,5bded8,0,18a19a,1')
            // ->chxl("0:|10|20|30|40|50|60|70|80|90|100|1:|".$data->implode('|')."|")
            ->chd('t:'.implode(",", $results))
            ->chxr('1,0,100')
            ->chxl("0:|".$data->implode('|')."|1:|10|20|30|40|50|60|70|80|90|100|")
            ->chbr($data->count())->chxt('x,y')->chs('700x500');

            try {
                $pieUrl = $pie->toDataURI();
            } catch (\Throwable $th) {
                $pieUrl = null;
            }

            if ($assessment) {
                $pdf = \PDF::loadView('childassessmentpdf', ['data' => $childassessment->resource, 'chart' =>  $pieUrl, 'sortTables' => $sortTable], [
                   'format' => 'A4-L',
                   'orientation' => 'L',
                   'mode' => 'c'
                  ]);
                return $pdf->stream('ChildAssessment.pdf');
            }
            return "no assessment found";
        } catch(\Throwable $e) {
            return $e;
        }
    }

    public function openPDFMinistry(Request $request, $assessment)
    {
        $assessmentData = ChildAssessment::with([
            'categories.skill_category',
            'categories.skills.skill', 'categories.skills.answer', 'categories.skills.answer.point',
            'assessment', 'created_by', 'approved_by', 'child', 'child.school', 'child.specialists', 'child.specialists.speciality',
            'advanced_plans', 'advanced_plans.items'
       ])->find($assessment);

        $childassessment =  new ChildAssessmentResource($assessmentData);

        $results = [];
        $sortTable = [];
        foreach ($childassessment->resource->categories->pluck('skills') as $key => $skills) {
            $skilssCount = count($skills);
            if ($skilssCount) {
                $result = $skills->where('point_type', 'PowerPoint')->count() * (100 / ($skilssCount ? $skilssCount : 1));
                $big =  $skills->where('point_type', 'PowerPoint')->count() > $skills->where('point_type', 'WeakPoint')->count()
                ? $skills->where('point_type', 'PowerPoint')->count() : $skills->where('point_type', 'WeakPoint')->count();
                $sortTable[$skills[0]->child_assessment_skill_categorie_id] = $big ;
                $results[] = ceil($result);
            }
        }
        ksort($sortTable);

        $timeTable = TimeTable::with(['class.school', 'class.teacher', 'days.periods.subjects'])
        ->where('class_id', $childassessment->resource->child->class_room_id)->get();
        $days = [
            [
                'name_ar' =>  "الاحد",
                'value' =>  2
            ],
            [
                'name_ar' =>  "الاتنين",
                'value' =>  3
            ],
            [
                'name_ar' =>  "الثلاثاء",
                'value' =>  4
            ],
            [
                'name_ar' =>  "الاربعاء",
                'value' =>  5
            ],
            [
                'name_ar' =>  "الخميس",
                'value' =>  6
            ],
        ];

        // return $childassessment->resource->child;


        $pdf = \PDF::loadView('childassessmentministrypdf', [
            'data' => $childassessment->resource,
            'sortTables' => $sortTable,
            'advanced_plans' => $childassessment->resource->advanced_plans->count() ? $childassessment->resource->advanced_plans[0]->items->groupBy('skill.skill_category_id') : [],
            'timeTable' => $timeTable,
            'days' => collect($days)
        ], [
            'format' => 'A4-L',
            'orientation' => 'L',
            'mode' => 'c'
           ]);
        return $pdf->stream('ChildAssessmentMinistry.pdf');
    }

    public function compareAssesments(Request $request)
    {
        $ids = explode(',', $request->get('ids'));

        app()->setLocale("en");
        ini_set("pcre.backtrack_limit", "1000000000");

        $assessment = ChildAssessment::with([
         'categories.skill_category',
         'categories.skills.skill', 'categories.skills.answer', 'categories.skills.answer.point',
         'assessment', 'created_by', 'approved_by', 'child', 'child.specialists', 'child.specialists.speciality'
        ])->find($ids)->sortBy('created_at');
        $childassessment =  new ChildAssessmentResource($assessment);
        $chart = new \ImageCharts();
        $pies = [];

        $categories = [];

        foreach ($childassessment->resource as $assessmentChild) {
            $results = [];

            $categories =  array_merge($categories, $assessmentChild->categories->pluck('skill_category_id')->toArray());

            foreach ($assessmentChild->categories->pluck('skills') as $key => $skills) {
                $skilssCount = count($skills);
                if ($skilssCount) {
                    $result = $skills->where('point_type', 'PowerPoint')->count() * (100 / ($skilssCount ? $skilssCount : 1));
                    $big =  $skills->where('point_type', 'PowerPoint')->count() > $skills->where('point_type', 'WeakPoint')->count()
                    ? $skills->where('point_type', 'PowerPoint')->count() : $skills->where('point_type', 'WeakPoint')->count();
                    // $categories[$skills[0]->child_assessment_skill_categorie_id] = $skills[0]->child_assessment_skill_categorie_id ;
                    $results[] = ceil($result);
                }
            }

            $data = $assessmentChild->categories->filter(function ($cat, $key) {
                return $cat->skills->count() > 0;
            })->pluck('skill_category.name.ar');

            $pie = $chart
            ->cht('bvs')
            // ->chxs('0,min40')
            ->chf('b0,lg,0,5bded8,0,18a19a,1')
            ->chd('t:'.implode(",", $results))
            ->chxr('1,0,100')
            ->chxl("0:|".$data->implode('|')."|1:|10|20|30|40|50|60|70|80|90|100|")
            ->chbr($data->count())->chxt('x,y')->chs('700x500');

            try {
                $pieUrl = $pie->toDataURI();
            } catch (\Throwable $th) {
                $pieUrl = null;
            }
            $pies[] = $pieUrl;
        }
        $categories = array_unique($categories);

        // return view('childassessmentspdf', ['data' => $childassessment->resource, 'charts' => $pies, 'categories' => $categories]);
        $pdf = \PDF::loadView('childassessmentspdf', ['data' => $childassessment->resource, 'charts' => $pies, 'categories' => $categories], [
            'format' => 'A4-L',
            'orientation' => 'L',
            'mode' => 'c'
           ]);
        return $pdf->stream('ChildAssessment.pdf');
    }

    public function comparedAssesments(Request $request)
    {
        $ids = explode(',', $request->get('ids'));

        app()->setLocale("en");
        ini_set("pcre.backtrack_limit", "1000000000");

        $assessments = ChildAssessment::with([
            'categories.skill_category',
            'categories.skills.skill', 'categories.skills.answer', 'categories.skills.answer.point',
            'assessment', 'created_by', 'approved_by', 'child', 'child.specialists', 'child.specialists.speciality'
        ])
        ->withCount('categories')
        ->find($ids)->sortByDesc('categories_count');

        $pies = [];
        $lastRow = [];
        $usedIds = [];
        $categoriesWithCounts = [];
        $categories = $assessments->pluck('categories');

        foreach ($categories as $key => $assessmentCategories) {
            $categoriesNames = [];
            $chart = new \ImageCharts();
            $reversedKey = $key == 0 ? 1 : 0;

            foreach ($assessmentCategories as $k => $category) {
                $result2 = null;
                $category->compared_category = null;
                $skillsCount = $category->skills->count();

                $category->power_skills_count = $category->skills->where('point_type', 'PowerPoint')->count();
                $category->weak_skills_count = $category->skills->where('point_type', 'WeakPoint')->count();
                $result = $category->power_skills_count * (100 / ($skillsCount ? $skillsCount : 1));
                $categoriesNames[] = $category['skill_category']['name']['ar'] . " " . ($key+1);
                $results[] = ceil($result);
                $compared = $categories[$reversedKey]->where('skill_category_id', $category->skill_category_id)->first();
                if(in_array($category->id, $usedIds)) {
                    continue;
                }

                if($compared) {
                    $compared->power_skills_count = $compared->skills->where('point_type', 'PowerPoint')->count();
                    $compared->weak_skills_count = $compared->skills->where('point_type', 'WeakPoint')->count();
                    $skillsCount = $compared->skills->count();
                    $categoriesNames[] = $compared['skill_category']['name']['ar'] . " " . ($reversedKey+1);
                    $result2 = $category->power_skills_count * (100 / ($skillsCount ? $skillsCount : 1));
                    $results[] = ceil($result2);
                }

                $usedIds[] = optional($compared)->id;
                $category->compared_category = $compared;
                $categoriesWithCounts[$key][$k][] = $category;
                $categoriesWithCounts[$key][$k][] = $compared;
                $compared = null;
            }

            $pie = $chart
            ->cht('bvs')
            ->chf('b0,lg,0,5bded8,0,18a19a,1')
            ->chd('t:'.implode(",", $results))
            ->chxr('1,0,100')
            ->chxl("0:|".collect($categoriesNames)->implode('|')."|1:|10|20|30|40|50|60|70|80|90|100|")
            ->chbr(collect($categoriesNames)->count())->chxt('x,y')->chs('700x500');

            try {
                $pieUrl = $pie->toDataURI();
            } catch (\Throwable $th) {
                $pieUrl = null;
            }
            $pies[] = $pieUrl;
            $results = [];
            $categoriesNames = [];

            $lastRow[$key]['skills_count'] = round($categories[$key]->pluck('skills_count')->sum() / count($categories[$key]), 1);
            $lastRow[$key]['gap'] = round($categories[$key]->pluck('gap')->sum() / count($categories[$key]), 1);
            $lastRow[$key]['power_skills_count'] = round($categories[$key]->pluck('power_skills_count')->sum() / count($categories[$key]), 1);
            $lastRow[$key]['weak_skills_count'] = round($categories[$key]->pluck('weak_skills_count')->sum() / count($categories[$key]), 1);

        }

        $pdf = \PDF::loadView('compareAssessments', ['data' => $assessments, 'lastRow' => $lastRow, 'charts' => $pies, 'categories' => $categoriesWithCounts], [
            'format' => 'A4-L',
            'orientation' => 'L',
            'mode' => 'c'
           ]);
        return $pdf->stream('ChildAssessment.pdf');
    }

    public function child(Child $child)
    {
        $child->load('parents', 'classroom', 'specialists', 'disabilities', 'school', 'birth_city', 'city', 'parentsData');
        // return $child;
        app()->setLocale('ar');
        $pdf = \PDF::loadView('childinfo', ['data' => $child ], [
            'format' => 'A4-L',
            'orientation' => 'L',
            'mode' => 'c'
           ]);
        return $pdf->stream('childinfo.pdf');
    }

    public function userRating(Request $request)
    {
        $data = (new StatisticsController())->userAssessments($request, true);
        // dd($data);
        // return $child;
        app()->setLocale('ar');
        $pdf = \PDF::loadView('userRating', ['data' => $data ], [
            'format' => 'A4-L',
            'orientation' => 'L',
            'mode' => 'c'
           ]);
        return $pdf->stream('userRating.pdf');
    }

    public function sessionSkillsProgress(Request $request)
    {
        $data = (new ScheduleSessionController())->reports($request, true);
        $child = Child::find($request->child_id);
        $passedData = ['data' => $data, 'type'=> $request->reportType, 'from' => $request->start, 'to' => $request->end, 'child' => $child];
        $view = ($request->reportType ==  'monthly' || $request->reportType ==  'yearly') ? 'sessionSkillsProgress' : 'sessionSkillsProgressDetailed';

        app()->setLocale('ar');

        $pdf = \PDF::loadView($view, $passedData, [
            'format' => 'A4-L',
            'orientation' => 'L',
            'mode' => 'c'
        ]);
        // $pdf->setWatermarkImage('https://imgv3.fotor.com/images/side/add-watermark-to-a-product-image.png');

        return $pdf->stream('session-progress.pdf');
    }

    public function attendence(Classroom $classroom)
    {
        if (!request('date')) {
            return new JsonResponse(['message' => 'Not Date Specified.'], Response::HTTP_NOT_FOUND);
        }

        $classroom->load(['school', 'teacher', 'attendence.child', 'attendence' => fn ($q) => $q->where('date', 'like', request('date'))]);
        app()->setLocale('ar');
        $pdf = \PDF::loadView('classroomAttendence', ['data' => $classroom, 'date' => request('date') ], [
            'format' => 'A4-L',
            'orientation' => 'L',
            'mode' => 'c'
           ]);
        return $pdf->stream('classroom-attendence.blade.pdf');
    }

    public function classroomTimeTable(TimeTable $timeTable)
    {
        $timeTable->load(['class.school', 'class.teacher', 'days.periods.subjects']);
        if (!request('class_id') || $timeTable->class_id != request('class_id')) {
            return new JsonResponse(['message' => 'Not Class Specified or Invalid Class.'], Response::HTTP_NOT_FOUND);
        }

        $days = [
            [
                'name_ar' =>  "الاحد",
                'value' =>  2
            ],
            [
                'name_ar' =>  "الاتنين",
                'value' =>  3
            ],
            [
                'name_ar' =>  "الثلاثاء",
                'value' =>  4
            ],
            [
                'name_ar' =>  "الاربعاء",
                'value' =>  5
            ],
            [
                'name_ar' =>  "الخميس",
                'value' =>  6
            ],
        ];
        app()->setLocale('ar');
        $pdf = \PDF::loadView('classroomTimeTable', ['data' => $timeTable, 'days' => collect($days) ], [
            'format' => 'A4-L',
            'orientation' => 'L',
            'mode' => 'c'
           ]);
        return $pdf->stream('classroomTimeTable.blade.pdf');
    }

    public function allAssessment(Request $request)
    {
        if ($request->has('lang')) {
            app()->setLocale($request->input('lang'));
        }
        $data =  AssessmentCategory::get();
        // return view('all-assament', ['data' => $data]);
        $pdf = \PDF::loadView('all-assament', ['data' => $data], [
            'format' => 'A4-L',
            'orientation' => 'L',
            'mode' => 'c'
           ]);
        return $pdf->stream('Assessments.pdf');
    }

    public function specialistsPay(Request $request)
    {
        $data = (new SpecialistPayController())->index($request, true);
        $data = $data->items();

        $pdf = \PDF::loadView('specialistsPay', ['data' => $data, 'request' => $request], [
            'format' => 'A4-L',
            'orientation' => 'L',
            'mode' => 'c'
           ]);
        return $pdf->stream('specialistsPay.pdf');
    }

    public function fairCollections(Request $request)
    {
        $data = (new SessionFairCollectionController())->index($request, true);
        $data = $data->items();

        $pdf = \PDF::loadView('fairCollections', ['data' => $data, 'request' => $request], [
            'format' => 'A4-L',
            'orientation' => 'L',
            'mode' => 'c'
           ]);
        return $pdf->stream('specialistsPay.pdf');
    }

    public function sessionFair(Request $request)
    {
        $data = (new SessionFairController())->index($request, true);
        $data = $data->items();

        $pdf = \PDF::loadView('sessionFair', ['data' => $data, 'request' => $request], [
            'format' => 'A4-L',
            'orientation' => 'L',
            'mode' => 'c'
           ]);
        return $pdf->stream('specialistsPay.pdf');
    }

    public function childSessions(Child $child)
    {
        // if (!request('date')) {
        //     return new JsonResponse(['message' => 'Not Date Specified.'], Response::HTTP_NOT_FOUND);
        // }
        $request = request();

        $child->load([
            'sessions.skills.skillAnswer',
            'sessions.specialist',
            'sessions' => function ($q) use ($request) {
                $q->when(
                    $request->start && ! ($request->start && $request->end),
                    fn ($q) => $q->whereDate('schedule_date', '>=', $request->start)
                )
                ->when(
                    ($request->start && $request->end),
                    fn ($q) => $q->whereBetween('schedule_date', [$request->start, $request->end])
                )
                ->when(
                    $request->end && ! ($request->start && $request->end),
                    fn ($q) => $q->where('schedule_date', '<=', $request->end)
                );
            }
        ]);
        $sessions = ScheduleSessionLightResource::collection($child->sessions)->resource;
        // return $sessions;
        app()->setLocale('ar');
        $pdf = \PDF::loadView('child-sessions', ['sessions' => $sessions, 'child' => $child ], [
            'format' => 'A4-L',
            'orientation' => 'L',
            'mode' => 'c'
           ]);
        return $pdf->stream('child-sessions.blade.pdf');
    }
}

