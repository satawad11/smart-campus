# Plate model evaluation on real gate photos (VEH-007 part 2, VEH-008)

**Why:** the owner approved building plate reading with LPD-YuNet + PaddleOCR Thai on 2026-11-20
(`docs/decisions/OWNER-DECISIONS-2026-11-20.md`), but plate reading stays **off on every camera**
until the models are measured on photos from the college's own gate cameras and the owner approves
the thresholds. LPD-YuNet was trained on Chinese plates and the Thai OCR on general text, so the
accuracy on Thai plates is unknown until this is done.

## สรุปภาษาไทย

1. ถ่ายรูปรถผ่านประตูจากกล้องจริงประมาณ 100 รูป (กลางวัน กลางคืน ย้อนแสง รถยนต์ และมอเตอร์ไซค์ถ้ามี)
2. พิมพ์ทะเบียนและจังหวัดที่ถูกต้องของแต่ละรูปลงไฟล์ `labels.csv`
3. รันคำสั่งประเมินบน AI node ระบบจะบอกว่าอ่านถูกกี่เปอร์เซ็นต์ แยกตามสภาพแสง และใช้เวลาต่อรูปเท่าไร
4. ส่งตัวเลขสรุป (ไม่ต้องส่งรูป) ให้ผู้อนุมัติตัดสินว่าจะเปิดใช้หรือไม่ และใช้ค่าเกณฑ์เท่าไร

รูปและไฟล์ทะเบียนเป็นข้อมูลส่วนบุคคล (PDPA) เก็บไว้ในเครื่องของวิทยาลัยเท่านั้น ลบเมื่อประเมินเสร็จ

## 1. Photos and labels

- About 100 photos (more is better; VEH-007 proposed ~300), exported from the actual gate cameras at
  their real angle and distance: day, night, backlight, rain if possible; cars, pickups, motorcycles.
- A `labels.csv` (UTF-8, header row) next to the photos:

  ```csv
  file,plate,province,condition
  gate_0001.jpg,1กข 1234,กรุงเทพมหานคร,day
  gate_0002.jpg,9กต 789,เชียงใหม่,night
  ```

  `plate` is typed as on the plate (spaces and dashes are fine). `condition` is free text; results
  are reported per condition. Special plates the shared rules do not accept yet (see the owner
  decisions note) are counted as "invalid labels" and reported separately.

## 2. Run it on the AI node

The node image contains the models when the kit was built after VEH-008
(`scripts/fetch-plate-models.sh`, called by `scripts/package-ai-node.sh`).

```bash
docker cp ./plate-eval smart-campus-ai-node:/data/plate-eval
docker exec smart-campus-ai-node python -m app.plates.evaluate \
  --images /data/plate-eval --labels /data/plate-eval/labels.csv \
  --output /data/plate-eval/result.json
# optional: try another detector threshold (default 0.8) or detection size (default 640;
# 320 is about 4x faster but may miss plates)
docker exec smart-campus-ai-node python -m app.plates.evaluate \
  --images /data/plate-eval --labels /data/plate-eval/labels.csv --detection-threshold 0.6 --detector-input-width 320
# afterwards
docker exec smart-campus-ai-node rm -rf /data/plate-eval
```

The command prints a summary: photos, `plate_found_rate` (detector found a plate),
`plate_exact_match_rate` (normalised plate equal to the label), `province_match_rate`, and mean /
max milliseconds per photo on this node's CPU, overall and per condition. `result.json` adds one row
per photo (what was read, scores) for checking failures; it contains plates, so keep it local.

Nothing is sent to the backend or outside the campus.

## 3. Decision for the owner

Proposed starting targets (VEH-007, the owner may change them): full-plate exact match ≥ 95% in
daylight, ≥ 90% at night, province ≥ 97%, under 1 s per vehicle. With the numbers the owner decides:

| Decision | Where it is set |
|---|---|
| Use the models at all (or fall back to option E, a commercial engine) | owner decision record |
| Plate detector threshold | node `.env` `PLATE_DETECTION_THRESHOLD` (default 0.8) |
| Plate detection size | node `.env` `PLATE_DETECTOR_INPUT_WIDTH` (default 640) |
| Reads voted per vehicle | node `.env` `PLATE_READS_PER_VEHICLE` (default 8) |
| OCR confidence below which a read is `PLATE_LOW_CONFIDENCE` | backend `.env` `ALPR_OCR_MIN_CONFIDENCE` (default 0.90) |
| Switch plate reading on | `.env` `FEATURE_ALPR_ENABLED=true`, then การตั้งค่าระบบ → ALPR, then per camera **อ่านป้ายทะเบียน** |

If accuracy is too low, the next options are fine-tuning on a licensed Thai plate set (needs its own
PDPA review) or option E; both need a new owner decision.
