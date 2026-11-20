// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Shows a modal with the pass/fail result after an H5P (mod_hvp) content was submitted.
 *
 * @module     taskflowadapter_ksw/hvpresult
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Modal from 'core/modal';
import ModalEvents from 'core/modal_events';
import Notification from 'core/notification';
import {getString} from 'core/str';

const COMPONENT = 'taskflowadapter_ksw';
const GRADEDVERBS = ['completed', 'answered'];
const TOLERANCE = 0.00001;

let modalopen = false;

/**
 * Returns the score of a statement mod_hvp uses for grading, null for all other statements.
 *
 * Mirrors H5P.xAPICompletedListener: verb completed/answered on the root content only.
 *
 * @param {Object} event the H5P xAPI event
 * @returns {Object|null} score with raw and max
 */
const getGradedScore = (event) => {
    const statement = event?.data?.statement;
    if (!statement) {
        return null;
    }
    const verb = (statement.verb?.id ?? '').split('/').pop();
    if (!GRADEDVERBS.includes(verb)) {
        return null;
    }
    if (statement.context?.contextActivities?.parent) {
        return null;
    }
    const score = statement.result?.score;
    if (!score || !(score.max > 0) || typeof score.raw !== 'number') {
        return null;
    }
    return score;
};

/**
 * Opens the result modal.
 *
 * @param {boolean} passed
 */
const showResult = async(passed) => {
    if (modalopen) {
        return;
    }
    modalopen = true;
    try {
        const modal = await Modal.create({
            title: getString('hvpresulttitle', COMPONENT),
            body: getString(passed ? 'hvpresultpassed' : 'hvpresultfailed', COMPONENT),
            show: true,
            removeOnClose: true,
        });
        modal.getRoot().on(ModalEvents.hidden, () => {
            modalopen = false;
        });
    } catch (error) {
        modalopen = false;
        Notification.exception(error);
    }
};

/**
 * Listens for submitted H5P results and reports if the grade to pass was reached.
 *
 * @param {number} gradepass grade to pass of the activity
 * @param {number} grademax maximum grade of the activity
 */
export const init = (gradepass, grademax) => {
    const dispatcher = window.H5P?.externalDispatcher;
    if (!dispatcher) {
        return;
    }
    dispatcher.on('xAPI', (event) => {
        const score = getGradedScore(event);
        if (score === null) {
            return;
        }
        // Same calculation as hvp_grade_item_update().
        const grade = score.raw / score.max * grademax;
        showResult(grade >= gradepass - TOLERANCE);
    });
};
