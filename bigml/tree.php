<?php
#
# Copyright 2012-2014 BigML
#
# Licensed under the Apache License, Version 2.0 (the "License"); you may
# not use this file except in compliance with the License. You may obtain
# a copy of the License at
#
#     http://www.apache.org/licenses/LICENSE-2.0
#
# Unless required by applicable law or agreed to in writing, software
# distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
# WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
# License for the specific language governing permissions and limitations
# under the License.

namespace BigML;

use BigML\predicate; 
use BigML\multivote.php; 
use BigML\ChiSquare.php; 
use BigML\prediction.php;

function get_instances($distribution) {
   /*
      Returns the total number of instances in a distribution
   */
   if ($distribution != null) {
      $sum = floatval(0);
      foreach($distribution as $key => $value) { 
         $sum += $value[1];
      }
      return $sum;
   } 

   return 0;
}

function unbiased_sample_variance($distribution, $distribution_mean=null) 
{
   /*
      Computes the standard deviation of a distribution in the syntax
   */
   $addition = 0.0;
   $count = 0.0;

   if ( $distribution_mean == null || !is_numeric($distribution_mean) ) {
      $distribution_mean = mean($distribution);
   }

   foreach($distribution as $key => $value) {
      $addition += pow($value[0]-$distribution_mean , 2)   * $value[1];
      $count += $value[1];
   }   
   if ($count > 1) {
      return $addition/($count -1);
   }
   
   return NAN;
}

function regression_error($distribution_variance, $population, $r_z=1.96) 
{
   /*
      Computes the variance error
   */

   if ($population > 0) {
      $stats = new Stats();

      $ppf=AChiSq($stats::erf($r_z / sqrt(2) ), $population);
      if ($ppf != 0 ) { 
         $error = ($distribution_variance * ($population-1)) / $ppf;
         $error = $error * pow(sqrt($population)+$r_z ,2);   
         return sqrt($error/$population);
      }
   }

   return NAN;
}

function splitChildren($children) {
   /*
      Returns the field that is used by the node to make a decision.
   */
   $field = array();
   foreach($children as $child) {
      $predicate = $child->predicate;
      array_push($field, $predicate->field);
   }

   $field = array_unique($field);
   if (count($field) == 1) {
      return reset($field);
   }

   return null;
}

function mean($distribution) {
   /*
      Computes the mean of a distribution in the [[point, instances]] syntax
   */
   $addition = 0.0;
   $count = 0.0;

   foreach($distribution as $value) {
      $addition += $value[0] * $value[1];
      $count += $value[1];
   }   

   if ($count > 0) {
      return $addition/$count;
   }

   return NAN; 

}

function missing_brach($children) {
   /*
     Checks if the missing values are assigned to a special branch
   */
   foreach($children as $child) {
      $predicate = $child->predicate;
      if ($predicate->missing == true) {
         return true;
      }
   }

   return false;
}

function null_value($children) {
   /*
     Checks if the predicate has a None value
   */

   foreach($children as $child) {
      $predicate = $child->predicate;
      if (is_null($predicate->value)) {
         return true;
      }
   }

   return false;
}

function one_branch($children, $input_data) {
   /*
    Check if there's only one branch to be followed
    */
   $missing = array_key_exists(splitChildren($children), $input_data); 

   return ($missing || missing_branch($children) || null_value($children));
}

class Tree {

   const LAST_PREDICTION = 0;
   const PROPORTIONAL = 1;
   const BINS_LIMIT = 32;

   public $fields;
   public $objective_field;
   public $objective_id;
   public $output;
   public $predicate;
   public $id;
   public $children;
   public $regression;
   public $count;
   public $confidence;
   public $distribution;
   public $parent_id;
   public $max;
   public $min;
   public $median;
   public $impurity;
   public $distribution_unit;

   public function __construct($tree, $fields, $objective_field=null, $root_distribution=null, $parent_id=null, $ids_map=null, $subtree=true, $tree_info=null) {

      $this->fields = $fields;
      $this->objective_field = $objective_field;
      $this->objective_id = $objective_field;
      $this->output = $tree->output;

      if ($tree->predicate instanceof STDClass)  {
         $this->predicate = new Predicate($tree->predicate->operator, $tree->predicate->field, $tree->predicate->value, property_exists($tree->predicate, "term") ? $tree->predicate->term : null);
      } else {
	 $this->predicate = true;
      }

      if (property_exists($tree, 'id') ) {
         $this->id = $tree->id;
         $this->parent_id = $parent_id;
         if (is_array($ids_map)) {
             $ids_map[$this->id] = clone $this;
         }

      } else {
         $this->id = null;
      }

      if (property_exists($tree, 'children') ) {
         $this->setChilds($tree->children,$ids_map, $subtree, $tree_info);
      } else {
         $this->children = array();
      }

      $this->regression = $this->is_regression();

      if ($this->regression && (array_key_exists("regresssion", $tree_info) ? $tree_info["regression"] : true) ) {
          $tree_info["regression"] = $this->regression;
      }

      $this->count = $tree->count;
      $this->confidence = property_exists($tree, "confidence") ? $tree->confidence : null;
      $this->distribution = null;
      $this->max = null;
      $this->min = null;
      $summary = null;

      if (property_exists($tree, 'distribution') ) {
         $this->distribution = $tree->distribution;
      } elseif (property_exists($tree, 'objective_summary') ) {
         $summary = $tree->objective_summary;
         if (property_exists($summary, 'bins')) {
             $this->distribution = $summary->bins;
	     $this->distribution_unit = 'bins';
         } elseif (property_exists($summary, 'counts') ) {
             $this->distribution = $summary->counts;
	     $this->distribution_unit = 'counts';
         }  elseif (property_exists($summary, 'categories') ) {
             $this->distribution = $summary->categories;
	     $this->distribution_unit = 'categories';
         }
      } else {
         $summary = $root_distribution;
         if (property_exists($summary, 'bins')) {
             $this->distribution = $summary->bins;
	     $this->distribution_unit = 'bins';
         } elseif (property_exists($summary, 'counts') ) {
             $this->distribution = $summary->counts;
	     $this->distribution_unit = 'counts';
         }  elseif (property_exists($summary, 'categories') ) {
             $this->distribution = $summary->categories;
	     $this->distribution_unit = 'categories';
         }
      }

      if ($this->regression) {

         if (array_key_exists("max_bins", $tree_info)) {
	    $tree_info["max_bins"] = max($tree_info["max_bins"], count($this->distribution));
	 } else {
	    $tree_info["max_bins"]=count($this->distribution);
	 }

         $this->median = null;

         if ($summary != null) {
            $this->median = $summary->median;
         } 

         if (!$this->median) {
            $this->median = dist_median($this->distribution, $this->count);
         }

         if (property_exists($summary, "maximum")) {
            $this->max = $summary->maximum;
         } else {
             foreach($this->distribution as $key => $instances) {
                if ($this->max == null or $key > $this->max) {
                   $this->max = $key;
                }
             } 
         }

         if (property_exists($summary, "minimum")) {
            $this->max = $summary->minimum;
         } else {
             foreach($this->distribution as $key => $instances) {
                if ($this->max == null or $key < $this->max) {
                   $this->max = $key;
                }
             } 
         } 

      }
      
      $this->impurity = null;

      if (!$this->regression && $this->distribution != null) {
         $this->impurity = $this->gini_impurity();
      } 

   }

   public function gini_impurity() {
      /*
        Returns the gini impurity score associated to the distribution in the node
       */
      $purity = floatval(0);
      if ($this->distribution == null) {
         return null;
      } 
      foreach($this->distribution as $distribution) {
        $purity+=pow(($distribution[1]/floatval($this->count)), 2);
      }

      return (floatVal(1) - $purity)/2; 
 
   }

   public function setChilds($children, $ids_map, $subtree, $tree_info) {
       
      $this->children = array();

      foreach ($children as $var => $child) {
          $t = new Tree($child, $this->fields, $this->objective_field, null, $this->id, $ids_map, $subtree, $tree_info);
          array_push($this->children, $t);
      }
      #return $this->children;
   }

   private function is_regression() {
      /*
        Checks if the subtree structure can be a regression
      */
      if (is_string($this->output)) {
         return false;
      } elseif ($this->children == null) {
         return true;
      } else {
         foreach ($this->children as $var => $child) {
            if (is_string($child->output) )
                return false;
            }
         }
	 return true;
   }

   public function predict($input_data, $path=null, $missing_strategy=Tree::LAST_PREDICTION) 
   {
      /*
         Makes a prediction based on a number of field values.
         The input fields must be keyed by Id. There are two possible
           strategies to predict when the value for the splitting field
           is missing:
         0 - LAST_PREDICTION: the last issued prediction is returned.
         1 - PROPORTIONAL: as we cannot choose between the two branches
            in the tree that stem from this split, we consider both. The
            algorithm goes on until the final leaves are reached and
            all their predictions are used to decide the final prediction.
      */
      if ($path == null) {
         $path = array();
      }

      if ($missing_strategy == Tree::PROPORTIONAL) {
         $predict_pro = $this->predict_proportional($input_data, $path);

         $final_distribution = $predict_pro[0];
         $d_min = $predict_pro[1];
         $d_max = $predict_pro[2];
         $last_node = $predict_pro[3];
 
         $distribution = array(); 

         if ($this->regression) {
          
            // singular case
            // when the prediction is the one given in a 1-instance node
            if (count($final_distribution) == 1) {
               foreach ($final_distribution as $prediction => $instances) {
                   if ($instances == 1) { 
                       return new Prediction($last_node->output, $path, $last_node->confidence,
                                        $last_node->distribution, $instances, $last_node->distribution_unit, $last_node->median,
                                        $last_node->children, $last_node->min, $last_node->max);
                   }
                   break;
               }
            }

            ksort($final_distribution);

            foreach ($final_distribution as $key => $val) {
                array_push($distribution, array(floatval($key), $val));
            }
             
            $distribution_unit = 'counts';
            if (count($distribution) > Tree::BINS_LIMIT) {
               $distribution_unit = 'bins';
            }
            $distribution = merge_bins($distribution, Tree::BINS_LIMIT);
            $prediction = mean($distribution);
            $total_instances = 0;

            foreach ($distribution as $key => $val) { 
                $total_instances+=$val[1];
            }

            $confidence = regression_error(unbiased_sample_variance($distribution, $prediction), $total_instances);
            return new Prediction($prediction, $path, $confidence, $distribution, $total_instances, $distribution_unit, dist_median($distribution, $total_instances), $last_node->children, $d_min, $d_max);

         } else {
            ksort($final_distribution);

	    $distribution = array();
            foreach ($final_distribution as $key => $val) {
               array_push($distribution, array($key, $val));
            }
            return new Prediction($distribution[0][0], $path, ws_confidence($distribution[0][0], $final_distribution), $distribution, get_instances($distribution), 
	                          'categorial', null, $last_node->children, null, null);
         }
 
      } else {
         if ($this->children != null) {  #&&  array_key_exists(splitChildren($this->children), $input_data) ) {
            foreach ($this->children as $child) {
               if ($child->predicate->apply($input_data, $this->fields)) {
                  $new_rule = $child->predicate->to_rule($this->fields); 
                  array_push($path, $new_rule);
                  return $child->predict($input_data, $path);
               }
            }
         }
         return new Prediction($this->output, $path, $this->confidence, $this->distribution, get_instances($this->distribution), 
	                       $this->distribution_unit, ($this->regression == null ? null : $this->median), $this->children, 
			       ($this->regression == null ? null : $this->min),($this->regression == null ? null : $this->max));
      }

   }

   function predict_proportional($input_data, $path=null, $missing_found=false, $median=false) {
      /*
         Makes a prediction based on a number of field values averaging
         the predictions of the leaves that fall in a subtree.

         Each time a splitting field has no value assigned, we consider
         both branches of the split to be true, merging their predictions.
         The function returns the merged distribution and the
         last node reached by a unique path.
      */

      if ($path==null) {
         $path == array();
      }

      $final_distribution = array();

      if ($this->children == null) {
         $a = array(); 
         foreach($this->distribution as $x) {
            $a[strval($x[0])] = $x[1];
         }

         return array(merge_distributions(array(), $a), $this->min, $this->max, $this);
      }

      if (one_branch($this->children, $input_data)) {
         foreach($this->children as $child) {
            $predicate = $child->predicate;

            if ($predicate->apply($input_data, $this->fields)) {
               $new_rule = $predicate->to_rule($this->fields);
               if (!in_array($new_rule, $path) && !$missing_found) {
                  array_push($path, $new_rule);
               }
               return $child->predict_proportional($input_data, $path, $missing_found, $median);
            }
 
         }
      } else {
         $missing_found = true;
         $minimus = array();
         $maximus = array();

         foreach($this->children as $child) {
	    $predict_pro = $child->predict_proportional($input_data, $path, $missing_found, $median);
            $subtree_distribution = $predict_pro[0];
	    $subtree_min = $predict_pro[1];
	    $subtree_max = $predict_pro[2];

            if ($subtree_min != null) {
               array_push($minimus, $subtree_min); 
            }
            if ($subtree_max != null) {
               array_push($maximus, $subtree_max); 
            }

            $final_distribution = merge_distributions($final_distribution, $subtree_distribution);
         }
         
         $min_value = null;
         $max_value  = null;

         if (!empty($minimus)) {
            $min_value=min($minimus);
         }

         if (!empty($maximus)) {
            $max_value=max($maximus);
         }
         return array($final_distribution, $min_value, $max_value, $this);
      }

   }

   public function rules($out, $ids_path=null, $subtree=true) {
      /*
         Prints out an IF-THEN rule version of the tree.
      */
      foreach($this->sort_fields($this->fields) as $key => $field) {
         $slug = $this->slugify($this->fields->{$field[0]}->name);
         $this->fields->{$field[0]}->slug = $slug;
      }

      fwrite($out, $this->generate_rules(0, $ids_path, $subtree));
      fflush($out);
   }

   private function generate_rules($depth=0, $ids_path=null, $subtree=true)
   {
      /*
        Translates a tree model into a set of IF-THEN rules.
      */
      $INDENT = utf8_encode('    ');
      $rules = utf8_encode("");
      $children = filter_nodes($this->children, $ids_path, $subtree);

      if ($children != null) {
         foreach($children as $child) {
             $a = str_repeat($INDENT,$depth);
             $b = $child->predicate->to_rule($this->fields, 'slug');
             $c = ($child->children != null ) ? "AND" : "THEN";
             $d = $child->generate_rules($depth+1, $ids_path, $subtree); 

             $rules = $rules . $a . " IF " . $b . " " . $c . "\n" . $d;
         }
      } else {
         $a = str_repeat($INDENT,$depth);
         $b = ($this->objective_id != null) ? $this->fields->{$this->objective_id}->slug : "Prediction"; 
         $rules = $rules . $a . " " . $b . " = " . $this->output . "\n"; 
      }

      return $rules;
   }

   private function slugify($name, $reserved_keywords=null, $prefix='') {
      /*
         Translates a field name into a variable name.
      */
      $name = strtolower(utf8_encode($name));
      $name = preg_replace('/[^\da-z]+/i', '_', $name); 

      if (is_integer($name[0])) {
         $name = 'field_' . $name;
      } 

      if ($reserved_keywords != null) {
         if (in_array($name, $reserved_keywords)) {
            $name = $prefix . $name;
         }
      }

      return $name;
   }

   private function sort_fields($fields) {
      /*
         Sort fields by their column_number but put children after parents.
      */
      $fathers = array();
      $children = array();

      $new_array_childs = array();
      $new_array_fathers = array();

      foreach($this->fields as $key => $value) {

         if (property_exists($value, "auto_generated") ) {
            $new_array_childs[$key] = $value->column_number;
         } else {
            $new_array_fathers[$key] = $value->column_number;
         } 
      }
       
      arsort($new_array_childs);
      asort($new_array_fathers);
      
      $fathers_keys = array(); 

      foreach($new_array_childs as $key => $value) {
         array_push($children, array($key, $fields->{$key}));
      }

      foreach($new_array_fathers as $key => $value) {
         array_push($fathers, array($key, $fields->{$key}));   
         array_push($fathers_keys, $key);
      }

      foreach($children as $child => $value) {
          $index = array_search($value[1]->parent_ids[0], $fathers_keys);
         if ($index >=0) {
            $fathers = array_slice($fathers, 0, $index, true) + array(array($value, $child)) + array_slice($fathers, $index+1, count($fathers), true);
         } else {
            array_push($fathers, array($value, $child));
         }
      }
      
      return $fathers;
   }
}

function filter_nodes($node_list, $ids=null, $subtree=true) 
{
   /*
      Filters the contents of a nodes_list. If any of the nodes is in the
      ids list, the rest of nodes are removed. If none is in the ids list
      we include or exclude the nodes depending on the subtree flag.
   */
   if ($node_list == null) {
      return null;
   }
   $nodes = $node_list;
   if ($ids == null) {
      foreach($nodes as $node) {
         if ($ids != null && in_array($ids, $node->id)) {
           return array($node);
         } 
      }
   }

   if (!$subtree) {
      $nodes = array();
   }
   return $nodes;
}

function missing_branch($children) {
  foreach($children  as $child) {
     if ($child->predicate->missing){
        return true;
     }
  }
  return false;
}

function dist_median($distribution, $count) 
{
  /*
    "Returns the median value for a distribution
   */
   $counter = 0;
   $previous_value= null;

   foreach($distribution as $key => $value) {

       $counter += $value[1];
       if ($counter > ($count/2)) {
          if (($count % 2) != 0 && ($counter -1) == ($count/2) && $previos_value != null ) {
             return ($value[0] + $previous_value) / 2;
          }
          return $value[0];
       }
       $previous_value=$value[0];
   }
   return null; 
}

function erf($x) {
    # constants
    $a1 =  0.254829592;
    $a2 = -0.284496736;
    $a3 =  1.421413741;
    $a4 = -1.453152027;
    $a5 =  1.061405429;
    $p  =  0.3275911;

    # Save the sign of x
    $sign = 1;
    if ($x < 0) {
        $sign = -1;
    }
    $x = abs($x);

    # A&S formula 7.1.26
    $t = 1.0/(1.0 + $p*$x);
    $y = 1.0 - ((((($a5*$t + $a4)*$t) + $a3)*$t + $a2)*$t + $a1)*$t*exp(-$x*$x);

    return $sign*$y;
}


?>
