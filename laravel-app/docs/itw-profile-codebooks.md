# ITW profile descriptions: evidence and resolution

Verified 2026-10-19 using only reference tables and the official manual. No student records were examined during dictionary research.

## Primary manual

The [Department of Learning Encouragement student-registration page](https://www.dole.go.th/th/student-registration) links the [ITW51 2.0 manual, 10 April 2024](https://drive.google.com/file/d/1CB6Fe_EUzjlAvA4Hb74KuGlTifsVUZBE/view), in its [manual folder](https://drive.google.com/drive/folders/1017OYUmFC1K-J1xPrc51xWvcKESb5lev).

- Printed page 25 / PDF page 26, figure 3.27: six religion codes, in order: Buddhism, Christianity, Islam, Hinduism, Sikhism, other. This is important: a different agency's religion ordering would incorrectly swap Christianity and Islam.
- Printed pages 26-27 describe editable religion reference entries. A supplied religion.dbf is authoritative over the documented defaults.
- Printed page 46 / PDF page 47, figure 5.4: the top marital-status field belongs to the parents; value 1 describes living together. Individual father and mother status fields separately show 01 with the same description. These are not alive/deceased flags. The manual does not enumerate the remaining status meanings, so they are deliberately not inferred.

## Local source dictionaries

Existing extracted ITW archives supplied OCCUPAT.DBF, occtyp.dbf and s_grade.dbf with ID/NAME fields. The local transfer archives did not include a religion reference table; the official manual therefore supplies the default religion mapping.

Qualification codes must be scoped by level and actual source table. For example, code 02 in the local level-2 s_grade.dbf is a primary-school qualification, while the same code in level 3 describes a different qualification. Two local archives also disagreed on level-3 code 08. Do not create one global qualification map or copy a code's meaning from another batch.

## Runtime behavior

1. Access checks select a student, district, level and active registered batch as before.
2. Resolve exactly one STUDENT.DBF directory in that batch without reading its records. Do not select an arbitrary school when multiple directories match.
3. Read only the allowlisted reference files; s_grade is restricted to the exact level directory. Shared dictionaries can also come from the same school's root or 0/ directory.
4. Reject external symlinks, corrupt/oversized files, non-character ID/NAME fields and conflicting normalized codes. A present but unusable dictionary does not trigger a guessed label.
5. Use documented religion defaults only when no religion reference file is supplied. Existing system dictionaries remain fallback sources for nationality, occupation and target groups. Only the family-status value explicitly verified in figure 5.4 is currently mapped.
6. Render descriptions only. Unknown meanings use an unresolved-description message; empty values render blank without placeholder text. Do not echo unknown raw codes on the profile screen.

No migration, imported-row update, or new production data import is required. Original raw API code fields are retained for compatibility. If extracted archives are unavailable, source-specific/custom qualification descriptions cannot be recovered from the numeric code alone. The live Windows ITW application and production MySQL latency were not tested.

## Family-field audit (2026-10-20)

A read-only check of the six locally extracted STUDENT.DBF files found multiple father/mother status codes, rather than a universal value of 01. Matching the parent names supplied in the reported screenshot found three archived records with the same relevant values: MARRYSTAT=1, FA_STATUS=01, MO_STATUS=01; FA_OCCP, MO_OCCP and PA_OCCP were blank. OCCP=05 belongs to the student, not to a parent. No identifying values or records are copied into this document.

The matching screenshot is consistent with these archived source fields and the manual's value-01 description. This does not independently confirm the family's current circumstances. The configured local database contained no imported student tables at audit time, so the deployed database was not verified. Missing parent occupations must be corrected in the source register and reimported; they cannot be inferred from the student's occupation.

The family screen labels individual status as the recorded ITW status. Empty profile values, including unrecorded occupations, render blank without substitute text; source zero values remain visible. Repository regression coverage checks that student, father, mother and guardian occupations remain independent, and that the parents' combined marital-status value does not override either individual's status.
