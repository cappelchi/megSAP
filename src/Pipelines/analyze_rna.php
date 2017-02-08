<?php

/**
	@page analyze_rna
	@todo can the STAR-Fustion reference replace the STAR reference?
*/

require_once(dirname($_SERVER['SCRIPT_FILENAME'])."/../Common/all.php");

error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);

// parse command line arguments
$parser = new ToolBase("analyze_rna", "RNA Mapping pipeline using STAR.");
$parser->addInfileArray("in_for", "Forward reads in fastq.gz file(s).", false);
$parser->addString("out_folder", "Output folder. (May contain sub-directories, which will then automatically be created, if missing)", false);
$parser->addString("out_name", "Output base file name, typically the processed sample ID (e.g. 'GS120001_01').", false, NULL);
//optional
$parser->addInfileArray("in_rev", "Reverse reads fastq.gz file(s) for paired end alignment.", true);
$parser->addInfile("system", "Processing system INI file (Determined from 'out_name' by default).", true);
$steps_all = array("ma", "rc", "an", "fu", "db");
$parser->addString("steps", "Comma-separated list of processing steps to perform.", true, implode(",", $steps_all));
$parser->addString("reference", "FASTA File that contains the reference genome used for mapping.", true, get_path("data_folder")."genomes/STAR/hg19");
$parser->addString("fusionDetectionReference", "Reference genome used for STAR-Fusion", true, get_path("data_folder")."/genomes/STAR-Fusion/hg19");
$parser->addString("gtfFile", "GTF File containing feature annotations used for read counting.", true, get_path("data_folder")."genomes/gtf/ucsc_refseq_hg19.gtf");
$parser->addString("GATKReference", "GATK reference fasta file", true, get_path("data_folder")."genomes/GATK/hg19/hg19_GATK.fa");
$parser->addString("featureType", "Feature type used for mapping reads to features.", true, "exon");
$parser->addString("gtfAttribute", "GTF attribute used as feature ID.", true, "gene_id");
$parser->addInt("threads", "The maximum number of threads used.", true, 4);
$parser->addFlag("noIndelRealign", "Skip InDel realignment step. By default InDel realignment is calculated.");
$parser->addFlag("stranded", "Specify whether a stranded protocol was used during library preparation. Default is non-stranded.");
$parser->addFlag("keepUnmapped", "Save unmapped reads as fasta files.");
$parser->addFlag("rmdup", "Remove PCR duplicates. This is only possible if bam files are produced!");
$parser->addFlag("sharedMemory", "Use shared memory for running STAR alignment jobs.");

extract($parser->parse($argv));

// Check steps
$steps = explode(",", $steps);
foreach($steps as $step)
{
	if (!in_array($step, $steps_all)) trigger_error("Unknown processing step '$step'!", E_USER_ERROR);
}

// Extract sub-directories and generating folder structure
if(empty($out_folder)) trigger_error("No output folder!", E_USER_ERROR);
if(empty($out_name))	trigger_error("No output prefix!", E_USER_ERROR);
$prefix = $out_folder."/".$out_name;

// Extract processing system information from DB
$sys = load_system($system, $out_name);

// Paired-End or Single-End ?
if(isset($in_rev)) {
  $parser->log("Running in paired end mode");
  $paired = True;
} else { //only single end mapping
  $parser->log("Running in single end mode");
  $paired = False;
}

// Check FASTQ quality encoding
$parser->log("!!Checking quality encoding format of the input files!!");
if($paired) {
	$files = array_merge($in_for, $in_rev);
} else {
	$files = array_merge($in_for);
}
foreach($files as $file) {
	list($stdout, $stderr) = $parser->exec(get_path("ngs-bits")."FastqFormat", "-in $file", true);
	if (!contains($stdout[2], "Sanger")) {
		trigger_error("Input file '$in_for' is not in Sanger/Illumina 1.8 format!", E_USER_ERROR);
	}
}

// Mapping Step
// Result file of the mapping step
$final_bam="{$prefix}.bam";
// Resulting QC files
$bam_sorted="{$prefix}Aligned.sortedByCoord.out.bam";
$qc_fastq = "{$prefix}_stats_fastq.qcML";
$qc_map = "{$prefix}_stats_map.qcML";
if(in_array("ma", $steps))
{
	// Merging, ReadQC, and adapter trimming
	if ($sys["adapter1_p5"]=="" && $sys["adapter2_p7"]=="") {
		trigger_error("No forward and/or reverse adapter sequence given!\nForward: ".$sys["adapter1_p5"]."\nReverse: ".$sys["adapter2_p7"], E_USER_ERROR);
	}
	if($paired) {
		// Paired-End: SeqPurge
		$qualTrim1 = $parser->tempFile("_trimmed.fastq.gz");
		$qualTrim2 = $parser->tempFile("_trimmed.fastq.gz");
		$parser->exec(get_path("ngs-bits")."SeqPurge", "-in1 ".implode(" ", $in_for)." -in2 ".implode(" ", $in_rev)." -out1 $qualTrim1 -out2 $qualTrim2 -a1 ".$sys["adapter1_p5"]." -a2 ".$sys["adapter2_p7"]." -qc $qc_fastq -threads ".$threads, true);
	} else {
		// Single-End: ReadQC + Skewer
		// Merge all input fastq.gz files into one file
		$reads_merged = $parser->tempFile(".fastq.gz");
		$parser->exec("cat", implode(" ", $in_for)." > $reads_merged", true);
		
		// ReadQC
		$parser->exec(get_path("ngs-bits")."ReadQC", "-in1 $reads_merged -out $qc_fastq", true);
		
		// Skewer
		$qualTrim = $parser->tempFile("_trimmed.fastq.gz");
		$qualTrim_basename = dirname($qualTrim)."/".basename($qualTrim, ".fastq.gz");
		$parser->exec(get_path("skewer"), "-x ".$sys["adapter1_p5"]." -y ".$sys["adapter2_p7"]." -m any -threads $threads -z -o $qualTrim_basename --quiet $reads_merged", true);
		rename($qualTrim_basename."-trimmed.fastq.gz", $qualTrim);
		// Remove the log file of skewer
		unlink($qualTrim_basename."-trimmed.log");
	}

	// Mapping with STAR
	$parser->log("!!Performing Mapping!!");
	if($paired){
		$align_command = "-in1 $qualTrim1 -in2 $qualTrim2 -prefix $prefix -genome $reference -p $threads";
	} else {
		$align_command = "-in1 $qualTrim -prefix $prefix -genome $reference -p $threads";
	}
	// Check for shared memory usage during mapping, default is to do not use shared memory allowing for multiple star instances.
	// Shared memory is only possible if no fusion detection is performed. 2-pass mode of STAR is recommended for fusion detection but crashes if shared memory is used.
	// This might change with newer STAR releases, but for STAR 2.5.2b this does not work.
	if($sharedMemory && !in_array("fu", $steps))	$align_command .= " -useSharedMemory";
	if($sharedMemory && in_array("fu", $steps))		$parser->log("Using shared memory and detecting fusion proteins is not possible at the same time. Disabling shared memory.");
	if($keepUnmapped)	$align_command .= " -keepUnmapped";
	if($rmdup)	$align_command .= " -rmdup";
	$parser->execTool("NGS/mapping_star_htseq.php", $align_command);

	// STAR result files
	if($rmdup) {
		$bam_mapped = "{$prefix}Aligned.sortedByCoord.rmdup.bam";
	} else {
		$bam_mapped = "{$prefix}Aligned.sortedByCoord.out.bam";
	}
	
	// InDel realignment using GATK
	$bam_realign="$bam_sorted";
	if(!$noIndelRealign) {
		//Index the bam file first
		$parser->exec(get_path("samtools"), "index $bam_sorted", true);
		//split and trim reads with n in the cigar string -> spliced reads
		$parser->log("Performing Split'N'Trim");
		$bam_split = $parser->tempFile(".sorted.split.bam");
		$parser->exec(get_path("GATK"), "-T SplitNCigarReads -R $GATKReference -I $bam_sorted -o $bam_split -rf ReassignOneMappingQuality -RMQF 255 -RMQT 60 -U ALLOW_N_CIGAR_READS", true);
		//perform indel realignment on splitted reads
		$parser->log("Performing IndelRealignment");
		$parser->execTool("NGS/indel_realign.php", "-in $bam_split -prefix $prefix");
		$bam_realign = "{$prefix}.realign.bam";
	} else {
		$bam_realign = $bam_mapped;
	}
	rename($bam_realign, $final_bam);
	
	// Create index for final output bam files
	$parser->exec(get_path("samtools"), "index $final_bam", true);
}

//count and normalize expression values
$counts_raw = "{$prefix}_counts_raw.tsv";
$counts_fpkm = "{$prefix}_counts_fpkm.tsv";
if(in_array("rc", $steps))
{
	$parser->log("Performing read counting");
	$args = array();
	if($paired) $args[] = "-paired";
	if($stranded) $args[] = "-stranded";
	$parser->execTool("NGS/read_counting_featureCounts.php", "-in $final_bam -out $counts_raw -threads $threads -gtfFile $gtfFile -featureType $featureType -gtfAttribute $gtfAttribute ".implode(" ", $args));


	// Normalize expression values
	$parser->log("Performing normalization of read counts");
	//remove the header line and just take the gene ID and counts column
	$counts_tmp = $parser->tempFile("_counts_tmp.tsv");
	$parser->exec("tail", "-n +2 $counts_raw | cut -f 1,7 > $counts_tmp", false);
	//normalize read counts
	$parser->execTool("NGS/normalize_read_counts.php", "-in $counts_tmp -out $counts_fpkm -gtf $gtfFile -method fpkm -feature $featureType -idattr $gtfAttribute -header");
}

//annotate
if(in_array("an", $steps))
{
	$parser->log("Performing annotation of read counts");
	$parser->execTool("NGS/annotate_count_file.php", "-in $counts_fpkm -out $counts_fpkm");
}

//detect fusions
if(in_array("fu",$steps))
{
	// STAR-Fusion has to know where the STAR and the perl executable exist, so change the environment
	$star_exe_dir = basename(get_path("STAR"), "STAR");						// Directory containing the STAR executable
	$path_saved = getenv("PATH");											// Save the original environment
	$path_new = "${star_exe_dir}:".get_path("perl").":${path_saved}";		// Set new PATH variable
	putenv("PATH=$path_new");
	// Set the environment such that the modules Set/IntervalTree.pm and DB_File.pm can be found by STAR-Fusion
	putenv("PERL5LIB=".get_path("perl")."/lib/site_perl/5.24.0/x86_64-linux:".get_path("perl")."/lib/5.24.0");
	// Run STAR-Fusion
	$fusion_command = "--genome_lib_dir $fusionDetectionReference";
	$fusion_command .= " -J {$prefix}Chimeric.out.junction";
	$fusion_command .= " --output_dir $out_folder";
	$parser->exec(get_path("STAR-Fusion"), $fusion_command, true);
	// Cleanup
	putenv("PATH=$path_saved");         // restore old value 
	putenv("PERL5LIB")	;				// unset this environment variable
	$parser->exec("rm -r", "$out_folder/star-fusion.filter.intermediates_dir", false);
	$parser->exec("rm -r", "$out_folder/star-fusion.predict.intermediates_dir", false);
	unlink("$out_folder/star-fusion.fusion_candidates.final");
	unlink("$out_folder/star-fusion.fusion_candidates.preliminary");
	unlink("$out_folder/star-fusion.fusion_candidates.preliminary.wSpliceInfo");
	unlink("$out_folder/star-fusion.fusion_candidates.preliminary.wSpliceInfo.ok");
	unlink("$out_folder/star-fusion.STAR-Fusion.filter.ok");
	unlink("$out_folder/star-fusion.STAR-Fusion.predict.ok");
	rename("$out_folder/star-fusion.fusion_candidates.final.abridged", "{$prefix}_var_fusions.tsv");
}

//import to database
$log_db  = "{$prefix}_log_db.log";
if (in_array("db", $steps))
{
	if(file_exists($log_db)) unlink($log_db);
	$parser->execTool("NGS/db_check_gender.php", "-in $final_bam -pid $out_name");
		
	// Update last_analysis column of processed sample in NGSD
	updateLastAnalysisDate($out_name, $final_bam);

	// Import QC data, do not import the insert size and target region read depth
	$qc_files = array($qc_fastq, $qc_map);
	$parser->execTool("NGS/db_import_qc.php", "-id $out_name -files ".implode(" ", $qc_files)." -force");
}
?>
