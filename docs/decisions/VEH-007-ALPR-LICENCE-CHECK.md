# VEH-007 — ALPR model licence check (part 1 of 2)

**Status:** licence check DONE (2026-11-16) · evaluation on real Thai plates NOT STARTED (needs photos) ·
model choice: owner approved building with candidates 1+2 (2026-11-20, `OWNER-DECISIONS-2026-11-20.md`); thresholds and go-live still need the real-photo evaluation. Part of ACR-VEH-01, decision D4.
**Scope:** which plate detector and which OCR model we may bundle into the AI node kit and run on CPU in
a commercial, on-premises product. Same discipline as ACR-AI-01 (face models): code licence and
**weights** licence are checked separately, training data is noted, open questions are listed.

This is research input from the upstream pages, **not a legal clearance**. Nothing here is bundled
or downloaded yet.

## สรุปภาษาไทย

- **ตัวเลือกที่ใบอนุญาตผ่านเบื้องต้น** (Apache-2.0 ทั้งโค้ดและไฟล์โมเดล ใช้เชิงพาณิชย์ได้ตามที่ระบุ):
  - ตัวหาตำแหน่งป้าย: **LPD-YuNet** จาก OpenCV Zoo แต่ฝึกด้วยป้ายจีน อาจหาป้ายไทยได้ไม่ดี
  - ตัวอ่านตัวอักษร: **th_PP-OCRv5_mobile_rec** จาก PaddleOCR อ่านภาษาไทยได้ แต่ฝึกกับข้อความทั่วไป ไม่ใช่ป้ายทะเบียน
- **ยังใช้ไม่ได้** เพราะไม่ระบุใบอนุญาตของไฟล์โมเดล หรือยืนยันภาษาไทยไม่ได้: open-image-models,
  fast-plate-ocr, EasyOCR
- **ทางเลือกเชิงพาณิชย์**: iApp Thai LPR รองรับป้ายไทยและจังหวัด ผู้ขายอ้างความแม่น 99.4% แต่เป็นบริการ
  คลาวด์ (ภาพออกนอกเครือข่าย) ถ้าต้องการติดตั้งในเครื่องเองต้องติดต่อฝ่ายขาย
- **ขั้นต่อไป** ต้องมีภาพป้ายทะเบียนจริงจากกล้องในวิทยาลัยเพื่อวัดความแม่น ก่อนเลือกโมเดลและขออนุมัติ

## Candidates checked

| # | Candidate | Role | Code licence | Weights licence (as published) | Training data | Thai plates | Verdict |
|---|---|---|---|---|---|---|---|
| 1 | OpenCV Zoo **LPD-YuNet** (`license_plate_detection_lpd_yunet_2023mar.onnx`, int8 variants) | plate detector | Apache-2.0 | Apache-2.0 — directory README: "All files in this directory are licensed under Apache 2.0 License" | Not named. README: "the model is trained with Chinese license plates" | None; README warns other plates "may be limited" | **Shortlist** (licence OK), accuracy on Thai plates unknown |
| 2 | PaddleOCR **th_PP-OCRv5_mobile_rec** | text-line OCR (Thai, English, digits) | Apache-2.0 (PaddleOCR repo) | Hugging Face metadata `license: apache-2.0`; no separate weights licence file | PaddleOCR's own Thai set: 4,261 text images; licence of that set not stated | Thai text yes; plates not mentioned. Own test accuracy 82.68 | **Shortlist** (licence OK), plate accuracy unknown |
| 3 | iApp **OpenThai 2.0 OCR Text** | Thai printed-text OCR | not stated on the card | Apache-2.0 (card table and metadata) | Court scans, parliament PDFs, Wikisource + synthetic lines from OFL/TLWG fonts; per-source licences not stated | Card: "Handwriting and scene text are weak" — plates are scene text | Backup OCR only; likely weak on plates |
| 4 | ankandrew **open-image-models** (YOLOv9 plate detectors) | plate detector | MIT | **not stated** | not stated; cites YOLOv9 | no country stated | **Not cleared** — weights licence and upstream YOLOv9 terms unknown |
| 5 | ankandrew **fast-plate-ocr** (global CCT models) | plate OCR | MIT | **not stated** | not stated on the pages read | Thailand not listed ("+65 countries", no list); Thai script not mentioned | **Not cleared** |
| 6 | JaidedAI **EasyOCR** | OCR | Apache-2.0 | not stated in README (weights from a model hub page, not read) | not stated | Thai not confirmed in the README | **Not cleared** (not needed if #2 works) |
| E | **iApp Thai LPR** (commercial service) | full ALPR | proprietary | n/a | vendor: 853-image test set "never used for training" | Yes, Thai plates and provinces (e.g. `th-83` Phuket); vendor accuracy 99.4% on its own set | **Fallback** (alternative E). Cloud API; on-premises only via sales |

iApp published prices (before 7% VAT, 2026): Gate S 1,500 baht / 10,000 images per month, Gate M
3,000 / 30,000, Gate L 7,500 / 100,000; overage 0.25 IC per image. Their terms page was not read.
Their benchmark PDF was not read. A cloud API sends plate images outside the campus network: that
needs a PDPA data-processing agreement and the owner's consent to the data flow.

Sources: [opencv_zoo LPD-YuNet](https://github.com/opencv/opencv_zoo/tree/main/models/license_plate_detection_yunet),
[CCPD](https://github.com/detectRecog/CCPD),
[PaddleOCR](https://github.com/PaddlePaddle/PaddleOCR),
[th_PP-OCRv5_mobile_rec](https://huggingface.co/PaddlePaddle/th_PP-OCRv5_mobile_rec),
[PP-OCRv5 multilingual](https://www.paddleocr.ai/main/en/version3.x/algorithm/PP-OCRv5/PP-OCRv5_multi_languages.html),
[OpenThai 2.0 OCR Text](https://huggingface.co/iapp/OpenThai2.0-OCR-Text),
[open-image-models](https://github.com/ankandrew/open-image-models),
[fast-plate-ocr](https://github.com/ankandrew/fast-plate-ocr),
[fast-plate-ocr model zoo](https://ankandrew.github.io/fast-plate-ocr/latest/inference/model_zoo/),
[EasyOCR](https://github.com/JaidedAI/EasyOCR),
[iApp Thai LPR](https://iapp.co.th/docs/smart-city/license-plate). All read 2026-11-16.

## Proposed pipeline to evaluate (not approved)

1. **Detect** the plate with LPD-YuNet (#1) on the camera frame or the vehicle crop.
2. **Rectify** the four corners to a flat plate image.
3. **Read** the plate's two rows with th_PP-OCRv5_mobile_rec (#2): top row = registration
   (`1กข 1234`), bottom row = province name.
4. **Normalise** with the VEH-003 rules: `ThaiPlate` for the top row; the bottom row goes through
   `ThaiProvince.resolve` (exact name, then a closest-name match to be added in VEH-008, since OCR
   often drops a vowel or tone mark).
5. **Vote** across frames of the same vehicle track; below the confidence threshold the result is
   `PLATE_LOW_CONFIDENCE` (VEH-006).

Fine-tuning #1 or #2 on Thai plates would need a training set whose licence allows it. Plates
photographed on campus are personal data (PDPA), so such a set needs the same care as face data.

## What the evaluation (part 2) needs from the owner

- **Photos:** at least ~300 plate images from the actual gate cameras: cars and motorcycles, day and
  night, rain if possible, the real camera angle and distance. Each with the true plate text and
  province typed by a person (the "ground truth").
- **Targets to approve:** proposed starting point — full-plate exact match ≥ 95% in daylight, ≥ 90% at
  night, province correct ≥ 97%, under 1 s per vehicle on the AI node CPU. The owner may change these.
- **Cloud yes/no:** whether sending plate images to an external service (option E) is acceptable at
  all. If not, E is only possible as an on-premises install bought from the vendor.

## Open questions upstream

| Item | Open question |
|---|---|
| LPD-YuNet | Training data is not named on the model page (the CCPD dataset is MIT-licensed, but the page does not say CCPD was used). |
| th_PP-OCRv5_mobile_rec | Provenance and licence of the 4,261-image Thai training set are not stated. |
| open-image-models / fast-plate-ocr | Licence of the released weights is not stated; for open-image-models also the upstream YOLOv9 licence. |

## Decision record

- 2026-11-16: owner chose "Licence check first" for VEH-007. This file is that check. The model choice
  and the thresholds still need the owner's approval after part 2, as ACR-VEH-01 D4 requires.
